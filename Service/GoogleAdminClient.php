<?php

namespace SAS\IRAD\GoogleAdminClientBundle\Service;

use Google_Auth_Exception;
use Google_Service_Exception;
use Google_Client;
use Google_Service_Directory;
use Google_Service_Directory_User;
use Google_Service_Directory_UserName;
use SAS\IRAD\GmailAccountLogBundle\Service\AccountLogger;
use SAS\IRAD\GmailOAuth2TokenBundle\Service\OAuth2TokenStorage;
use SAS\IRAD\PersonInfoBundle\PersonInfo\PersonInfoInterface;


class GoogleAdminClient {
    
    private $logger;
    private $storage;
    private $oauth_params;
    private $google_params;
    private $client;
    private $directory;
    
    public function __construct(AccountLogger $logger, OAuth2TokenStorage $storage, $oauth_params, $google_params) {
        
        $this->logger  = $logger;
        $this->storage = $storage;
        
        $this->oauth_params  = $oauth_params;
        $this->google_params = $google_params;

        $this->client    = new Google_Client();
        $this->directory = new Google_Service_Directory($this->client);
        // TODO: Check for existence of all params
        
        $this->client->setClientId($oauth_params['client_id']);
        $this->client->setClientSecret($oauth_params['client_secret']);
        $this->client->setRedirectUri($oauth_params['redirect_uri']);
        $this->client->setAccessType('offline');
        $this->client->setApprovalPrompt('force');
        $this->client->addScope($oauth_params['scopes']);
        
        $this->client->setAccessToken($this->storage->getAccessToken());
    }
    
    /**
     * Return the Google_Client object
     * @return Google_Client
     */
    public function getClient() {
        return $this->client;
    }
    
    /**
     * Return the Google OAuth2 parameters
     * @return array`
     */
    public function getOAuthParams() {
        return $this->oauth_params;
    }
    
    /**
     * Return the Storage object
     * @return OAuth2TokenStorage
     */
    public function getStorage() {
        return $this->storage;
    }
    
    
    /**
     * Wrapper for Google_Client createAuthUrl() method
     * @return url
     */
    public function createAuthUrl() {
        return $this->client->createAuthUrl();
    }
    
    /**
     * Wrapper for Google_Client refreshToken() method. Pass arg $required=false if you don't 
     * want a refresh failure to throw an exception. E.g., in the token admin pages, an invalid
     * token is okay since we may be generating a new token. But in scripts and web ui calls,
     * a failure should stop everything. 
     * @param boolean $required
     * @return url
     */
    public function refreshToken($required=true) {
        
        $tokenInfo = $this->storage->getRefreshToken();
        
        if ( $tokenInfo && isset($tokenInfo['token']) && $tokenInfo['token'] ) {
                 
            try {
                $this->client->refreshToken($tokenInfo['token']);
            } catch (Google_Auth_Exception $e) {
                if ( $required ) {
                    throw $e;
                }
            }
            // write new access token to cache file
            $this->storage->saveAccessToken($this->client->getAccessToken());
        }
    }    

    /**
     * Revoke our current refresh token
     * @return url
     */
    public function revokeRefreshToken() {

        $tokenInfo = $this->storage->getRefreshToken();
        
        if ( $tokenInfo && isset($tokenInfo['token']) && $tokenInfo['token'] ) {
            $this->client->revokeToken($tokenInfo['token']);
            $this->storage->deleteRefreshToken();
        }
    }    
    
    
    /**
     * Test if an access token is valid (i.e., not timed out or revoked)
     * @return boolean
     */
    public function isAccessTokenValid() {
        
        $accessToken = $this->client->getAccessToken();
        
        if ( !$accessToken ) {
            return false;
        }
        
        if ( $this->client->isAccessTokenExpired() ) {
            return false;
        }
        
        // extract actual token from json string
        $accessToken = json_decode($accessToken, true);
        $token = $accessToken['access_token'];
        
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL,"https://www.googleapis.com/oauth2/v1/tokeninfo?access_token=$token");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($curl);
        curl_close($curl);
         
        if ( !$tokenInfo = json_decode($response, true) ) {
            throw new \Exception("Unable to decode tokeninfo response. Can't validate token");
        }

        // is this our token?
        if ( isset($tokenInfo['issued_to']) && $tokenInfo['issued_to'] == $this->oauth_params['client_id'] ) { 
            return true;
        }
        
        return false;
    }
    
    
    /**
     * Validate a token returned from Google in OAuth2 callback. Resulting tokens are cached
     * @param string $code     The authentication code returned by Google OAuth2 
     * @param string $username Log who generated this token
     * @throws \Exception
     */
    
    public function authenticate($code, $username) {
        
        try {
            $result = $this->client->authenticate($code);
        } catch (\Google_Auth_Exception $e) {
            throw new \Exception("Invalid code returned after OAuth2 authorization.");
        }
         
        $tokenInfo = json_decode($this->client->getAccessToken());
         
        // store refresh token separately
        $refreshToken = array("token"      => $tokenInfo->refresh_token,
                              "created_by" => $username,
                              "created_on" => $tokenInfo->created);

        $this->storage->saveRefreshToken($refreshToken);
        
        // store remainder of token in token cache
        unset($tokenInfo->refresh_token);
        $this->storage->saveAccessToken(json_encode($tokenInfo));
    }
    
    /**
     * Return a new Directory API service object
     * @return Google_Service_Directory
     */
    public function getDirectoryService() {
        return new Google_Service_Directory($this->client);
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
        
        if ( !$this->isAccessTokenValid() ) {
            $this->refreshToken();
        }

        $user = false;
        
        // do we have a pennkey?
        if ( $personInfo->getPennkey() ) {
            $user_id = $this->getUserId($personInfo->getPennkey());
            $user = $this->_queryDirectoryUsers($user_id);
        }
        
        // if not found by pennkey, try by penn_id hash
        if ( !$user ) {
            // we should definitely have a penn_id
            $user_id = $this->getUserId($this->getPennIdHash($personInfo->getPennId()));
            $user = $this->_queryDirectoryUsers($user_id);
        }
        
        if ( $user ) {
            return new GoogleUser($user_id, $user, $personInfo, $this, $this->logger);
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
    private function _queryDirectoryUsers($user_id) {
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
        
        if ( !$this->isAccessTokenValid() ) {
            $this->refreshToken();
        }
        
        try {
            $this->directory->users->update($user->getUserId(), $user->getServiceDirectoryUser());
        } catch (Google_Service_Exception $e) {
            $this->logger->log($user->getPersonInfo(), 'ERROR', $e->getMessage());
            error_log($e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Create a new Google Service Directory User
     * @param PersonInfo $personInfo
     * @param string $password_hash sha1 hash of user's password
     * @throws Google_Service_Exception
     */
    public function createGoogleUser(PersonInfoInterface $personInfo, $password_hash) {
    
        if ( !$this->isAccessTokenValid() ) {
            $this->refreshToken();
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

        if ( !$this->isAccessTokenValid() ) {
            $this->refreshToken();
        }        
        
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