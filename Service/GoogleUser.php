<?php
/**
 * Front end for Google_Service_Directory_User 
 * @author robertom@sas.upenn.edu
 */


namespace SAS\IRAD\GoogleAdminClientBundle\Service;

use Google_Auth_Exception;
use Google_Service_Directory_User_Resource;
use Google_Service_Directory_User;
use SAS\IRAD\PersonInfoBundle\PersonInfo\PersonInfoInterface;


class GoogleUser {
    
    private $user_id;
    private $admin;
    private $user;
    private $personInfo;
    
    /**
     * Temp array to hold log entries for a user pending a "commit". If the commit
     * is successful, we add the log entries.
     * @var array
     */
    private $logEntries;
    
    public function __construct(Google_Service_Directory_User $user, GoogleAdminClient $admin, PersonInfoInterface $personInfo) {
        
        $this->admin  = $admin;
        $this->user   = $user;
        $this->personInfo = $personInfo;
        
        $this->logEntries = array();
    }
    

    /**
     * Update the google user account with any changes we have made. The API
     * is finicky if we try do multple updates as separate transactions.
     */
    public function commit() {
        $this->admin->updateGoogleUser($this);
        // reset logs
        $this->logEntries = array();
    }
    
    
    /**
     * Return the PersonInfo object used to construct this object
     * @return PersonInfoInterface
     */
    public function getPersonInfo() {
        return $this->personInfo;
    }
    
    
    /**
     * Set the first/last name on a Google account. Requires a call to commit()
     * to save.
     * @param array $name array("first_name" => $first, "last_name" => $last)
     * @throws \Exception
     */
    public function setName($name) {
        
        if ( !is_array($name) ) {
            throw new \Exception("GoogleUser::setName() expects array for input");
        }
        
        if ( !isset($name['first_name']) || !isset($name['last_name']) ) {
            throw new \Exception("Invalid array passed to GoogleUser::setName()");
        }
        
        // are we really changing anything?
        if ( $name['first_name'] != $this->getFirstName() || $name['last_name'] != $this->getLastName() ) {

            $this->user->getName()->setGivenName($name['first_name']);
            $this->user->getName()->setFamilyName($name['last_name']);

            $this->addLogEntry('UPDATE', 'GMail account first/last name updated.');
        }
    }
    
    /**
     * Set the password on a Google account. Requires a call to commit() to save.
     * @param string $password_hash sha1 hash of user password
     * @throws \Exception
     */
    public function setPassword($password_hash) {
        
        if ( !$password_hash ) {
            throw new \Exception("GoogleUser::setPassword requires parameter for input");
        }
        
        if ( !preg_match('/^[0-9a-f]{40}$/i', $password_hash) ) {
            throw new \Exception("GoogleUser::setPassword expects password parameter to be SHA-1 hash");      
        }        
        
        $this->user->setHashFunction('SHA-1');
        $this->user->setPassword($password_hash);        

        $this->addLogEntry('UPDATE', 'GMail password reset.');
    }
    
    /**
     * Set org unit on google account. Requires a call to commit() to save.
     * @param string $org_unit
     */
    public function setOrgUnit($org_unit) {
        $this->user->setOrgUnitPath("/$org_unit");
        $this->addLogEntry('UPDATE', "GMail account moved to OU=$org_unit.");
    }
    
    /**
     * Rename a google account that was created with a penn_id hash to new name based on pennkey
     */
    public function renameToPennkey() {
        
        if ( !$this->isPennIdHash() ) {
            throw new \Exception("GoogleUser::renameToPennkey() -- account is not a penn_id hash");
        }
        
        $pennkey = $this->personInfo->getPennkey();
        
        if ( !$pennkey ) {
            throw new \Exception("GoogleUser::renameToPennkey() expects a pennkey in the PersonInfo object");
        }
        
        $this->admin->renameGoogleUser($this, $pennkey, array('delete_alias' => true));
    }    
    
    /**
     * Activate a Google account: set the password and move to "activated-accounts" OU
     * @param string $password_hash sha1 hash of user password
     */
    public function activateAccount($password_hash) {

        if ( !$password_hash ) {
            throw new \Exception("GoogleUser::activateAccount requires parameter for input");
        }
        
        if ( $this->isPennIdHash() ) {
            // rename account using pennkey
            $this->renameAccount();
        }
        
        $this->setPassword($password_hash, array('commit' => false));
        $this->setOrgUnit('activated-accounts');
        $this->commit();
   }
    
    public function getFullName() {
        return $this->user->getName()->getFullName();
    }
    
    public function getFirstName() {
        return $this->user->getName()->getGivenName();
    }

    public function getLastName() {
        return $this->user->getName()->getFamilyName();
    }
    
    public function getUserId() {
        return $this->user->getPrimaryEmail();
    }
    
    public function getUsername() {
        list($username, $domain) = explode('@', $this->getUserId());
        return $username;
    }
    
    public function getServiceDirectoryUser() {
        return $this->user;
    }
    
    public function getLogEntries() {
        return $this->logEntries;
    }
    
    /**
     * Retrieve the account creation time from the Google directory user object
     * -- returned as string -- and convert to unix timestamp
     */
    public function getCreationTime() {
        return strtotime($this->user->getCreationTime());
    }
    
    public function getOrgUnitPath() {
        return $this->user->getOrgUnitPath();
    }

    public function isAccountPending() {
        return ( time() - $this->getCreationTime() < 86400 );
    }

    /**
     * Return the number hours/minutes until this account is available
     * @return string
     */
    public function getAccountAvailableWhen() {
        
        $creationTime = $this->getCreationTime();
        
        if ( !$creationTime ) {
            return "Account Not Provisioned";
        }
        
        $seconds = 86400 - (time() - $creationTime);
        
        if ( $seconds < 0 ) {
            return "Account is Ready";
        }
        
        $hours   = intval($seconds/3600);
        $minutes = intval(($seconds%3600)/60); 
        $when    = null;
        
        if ( !$hours ) {
            $minutes = max(1, $minutes);
        }
        
        if ( $hours ) {
            $when = "$hours hour";
            if ( $hours > 1 ) {
                $when .= 's';
            }
        }
        
        if ( $hours && $minutes ) {
            $when .= " and ";
        }
        
        if ( $minutes ) {
            $when .= " $minutes minute";
            if ( $minutes > 1 ) {
                $when .= 's';
            }
        }
        
        return $when;
    }    
    
    /**
     * Return true if the account name is a penn_id hash
     */
    public function isPennIdHash() {
        return ( (boolean) preg_match("/^[0-9a-f]{32}$/i", $this->getUsername()) );
    }
    
    /**
     * Return true if orgUnitPath is not in the "bulk" org
     */
    public function isActivated() {
        // TODO: Should org unit path be parameter?
        return ( $this->user->getOrgUnitPath() != "/bulk-created-accounts" );
    }
    
    /**
     * Add a log message to the queue. This will get written to the log when we commit
     * changes for this user
     * @param string $type Log type: INFO, UPDATE, ERROR, CREATE
     * @param string $message
     */
    private function addLogEntry($type, $message) {
        array_push($this->logEntries, compact('type', 'message'));
    }
}