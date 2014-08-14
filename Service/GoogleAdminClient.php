<?php

namespace SAS\IRAD\GoogleAdminClientBundle\Service;

use Google_Auth_Exception;
use Google_Client;
use Google_Service_Exception;
use Google_Service_Directory;
use Google_Service_Directory_User;
use Google_Service_Directory_UserName;
use SAS\IRAD\GmailAccountLogBundle\Service\AccountLogger;
use SAS\IRAD\GoogleOAuth2TokenBundle\Service\OAuth2Client;
use SAS\IRAD\PersonInfoBundle\PersonInfo\PersonInfoInterface;


class GoogleAdminClient {
    
    private $logger;
    private $google_params;
    private $client;
    private $directory;
    
    public function __construct(AccountLogger $logger, OAuth2Client $client, $google_params) {
        
        // verify required parameters
        $params = array('domain',
                        'relay_domain',
                        'hash_salt',
                        'account_creation');
        
        foreach ( $params as $param ) {
            if ( !isset($google_params[$param]) ) {
                throw new \Exception("Required parameter google_params.$param is not set.");
            }
        }
        
        $this->logger = $logger;
        $this->client = $client;
        $this->google_params = $google_params;

        $this->directory = new Google_Service_Directory($this->client->getGoogleClient());
    }
    
    /**
     * Build a google user id for the domain specified in parameters.yml
     * @param string $identifier
     * @return string
     */
    public function getUserId($identifier) {
        $user_id = $identifier . '@' . $this->google_params['domain'];
        return $user_id;
    }
    
    /**
     * Return a standard hash of the pennId so we can generate
     * non-guessable usernames for gmail accounts (to avoid a
     * sequential spam attack)
     * @param string $penn_id
     * @return string md5hash
     */
    public function getPennIdHash($penn_id) {
        // salt the penn_id with a random value
        return md5($penn_id . $this->google_params['hash_salt']);
    }
    
    
    /**
     * Return a google user object for the person specified in PersonInfo. Checks 
     * first for user matching by pennkey, then by penn_id hash. If neither is 
     * found, return false.
     * @param PersonInfoInterface $personInfo
     * @return GoogleUser
     */
    public function getGoogleUser(PersonInfoInterface $personInfo) {
        
        $this->client->prepareAccessToken();

        $user = false;
        
        // do we have a pennkey?
        if ( $personInfo->getPennkey() ) {
            $user_id = $this->getUserId($personInfo->getPennkey());
            $user = $this->__queryDirectoryUsers($user_id);
        }
        
        // if not found by pennkey, try by penn_id hash
        if ( !$user && $personInfo->getPennId() ) {
            $user_id = $this->getUserId($this->getPennIdHash($personInfo->getPennId()));
            $user = $this->__queryDirectoryUsers($user_id);
        }
        
        if ( $user ) {
            return new GoogleUser($user, $personInfo, $this, $this->logger);
        }
        
        return false;
    }
    
    /**
     * Perform query via Google Directory Service for specified user. Returns false if 
     * user is not found. If google returns any other exception, that exception is thrown.
     * @param string $user_id
     * @throws Google_Service_Exception
     * @returns Google_Service_Directory_User
     */
    private function __queryDirectoryUsers($user_id) {
        try {
            $user = $this->directory->users->get($user_id);
        } catch ( Google_Service_Exception $e ) {
            // if user doesn't exist
            if ( preg_match("/Resource Not Found: userKey/", $e->getMessage()) ) {
                // return false
                return false;
            }
            // some other error occurred, throw original exception
            throw $e;
        }
        return $user;
    }
    
    /**
     * Save changes made to a GoogleUser object
     * @param GoogleUser $user
     * @throws Google_Service_Exception
     */
    public function updateGoogleUser(GoogleUser $user) {
        
        $this->client->prepareAccessToken();
        
        try {
            $this->directory->users->update($user->getUserId(), $user->getServiceDirectoryUser());
        } catch (Google_Service_Exception $e) {
            $this->logger->log($user->getPersonInfo(), 'ERROR', $e->getMessage());
            error_log($e->getMessage());
            throw $e;
        }
    }
    

    /**
     * Rename a GoogleUser account. This is similar to the updateGoogleUser() method, but
     * we have to preserve the original username so that our update references the old name.
     * @param GoogleUser $user
     * @throws Google_Service_Exception
     */
    public function renameGoogleUser(GoogleUser $user, $newName, array $options = array()) {
    
        $this->client->prepareAccessToken();
    
        $oldName = $user->getUserId();
        $serviceDirectoryUser = $user->getServiceDirectoryUser();
        $serviceDirectoryUser->setPrimaryEmail($newName);

        try {
            $this->directory->users->update($oldName, $serviceDirectoryUser);
        } catch (Google_Service_Exception $e) {
            $this->logger->log($user->getPersonInfo(), 'ERROR', $e->getMessage());
            error_log($e->getMessages());
            throw $e;
        }
        
        if ( isset($options['delete_alias']) && $options['delete_alias'] === true ) {
            $this->directory->users_aliases->delete($newName, $oldName);
        }
    }    
    
    /**
     * Create a new Google Service Directory User
     * @param PersonInfo $personInfo
     * @param string $password_hash sha1 hash of user's password
     * @throws Google_Service_Exception
     */
    public function createGoogleUser(PersonInfoInterface $personInfo, $password_hash) {
    
        $this->client->prepareAccessToken();
    
        if ( !$personInfo->getPennId() ) {
            throw new \Exception("PersonInfo requires a penn_id value when passing to GoogleAdminClient::createGoogleUser");
        }
        
        if ( !preg_match('/^[0-9a-f]{40}$/i', $password_hash) ) {
            throw new \Exception("GoogleAdminClient::createGoogleUser expects password parameter to be SHA-1 hash");
        }
        
        
        if ( $personInfo->getPennkey() ) {
            $user_id = $this->getUserId($personInfo->getPennkey());
        } else {
            $user_id = $this->getUserId($this->getPennIdHash($personInfo->getPennId()));
        }
        
        $user = new Google_Service_Directory_User();
        $user->setPrimaryEmail($user_id);
        $user->setHashFunction('SHA-1');
        $user->setPassword($password_hash);
        
        // TODO: should this be a parameter?
        $user->setOrgUnitPath("/bulk-created-accounts");
        
        $name = new Google_Service_Directory_UserName();
        $name->setFamilyName($personInfo->getLastName());
        $name->setGivenName($personInfo->getFirstName());
        $user->setName($name);
        
        try {
            $this->directory->users->insert($user);
        } catch (Google_Service_Exception $e) {
            // log exception before throwing
            $this->logger->log($personInfo, 'ERROR', $e->getMessage());
            error_log($e->getMessage());
            throw $e;
        }
        
        // log success message
        $this->logger->log($personInfo, 'CREATE', "GMail account created.");
        $this->logger->log($personInfo, 'UPDATE', "GMail account moved to OU=bulk-created-accounts.");
    }

    /**
     * Delete a Google Account
     * @param GoogleUser $user
     * @throws Google_Service_Exception
     */
    public function deleteGoogleUser(GoogleUser $user) {

        $this->client->prepareAccessToken();    
        
        try {
            $this->directory->users->delete($user->getUserId());
        } catch (Google_Service_Exception $e) {
            // log exception before throwing
            $this->logger->log($user->getPersonInfo(), 'ERROR', 'Error deleting GMail account: ' . $e->getMessage());
            error_log($e->getMessage());
            throw $e;
        }
        
        // log success message
        $this->logger->log($user->getPersonInfo(), 'CREATE', "GMail account deleted.");        
    }
    
    
    /**
     * Return true if account_creation parameter is set to on/yes/true
     * @return boolean
     */
    public function isAccountCreationAvailable() {
        $param = $this->google_params['account_creation'];
        if ( is_bool($param) ) {
            return $param;
        }
        return in_array(strtolower($param), array('on', 'yes'));
    }
    
    
    /**
     * Return the email domain from google params
     * @return string
     */
    public function getEmailDomain() {
        return $this->google_params['domain'];
    }
    
    
    /**
     * Return an array of users in this Google domain. We can only 
     * retrieve 500 results at a time, so we need to "page" through
     * the result set to build our array.
     * @return array
     */
    public function getAllGoogleUsers() {

        $this->client->prepareAccessToken();
        
        $accounts = array();
        $options  = array('domain'     => $this->google_params['domain'],
                          'maxResults' => 500 );
        $nextPage = true;
        
        while ( $nextPage ) {
            $result = $this->directory->users->listUsers($options);
            
            foreach ( $result->getUsers() as $user ) {
                $email = $user->getPrimaryEmail();
                list($identifier, $domain) = explode('@', $email);
                $accounts[$identifier] = $email;
            }
            
            $nextPage = $result->getNextPageToken();
            if ( $nextPage ) {
                ## use pageToken parameter to retrieve more results
                $options['pageToken'] = $nextPage;
            }
        }

        return $accounts;
    }
}