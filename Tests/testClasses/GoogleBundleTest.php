<?php

/**
 * This is NOT a strict unit test of the GoogleAdminClient or GoogleUser
 * classes. It is more of a functional test to make sure the expected 
 * operations are working.
 * robertom@sas.upenn.edu
 */

use SAS\IRAD\PersonInfoBundle\PersonInfo\PersonInfo;


class GoogleBundleTest extends PHPUnit_Framework_TestCase {
    
    protected static $kernel;
    protected static $params;
    protected static $client;
    
    public static function setUpBeforeClass() {
        self::$kernel = new AppKernel('dev', true);
        self::$kernel->boot();
        
        self::$params = self::$kernel->getContainer()->getParameter('google_params');
        self::$client = self::$kernel->getContainer()->get('google_admin_client');
    }
    
    /**
     * TearDown assumes basic functionality is working to clean up
     * any test accounts created.
     */
    public static function tearDownAfterClass() {

        $users = array(array('penn_id' => '00112233',
                             'pennkey' => 'test1'),
                       array('penn_id' => '00112233'));

        foreach ( $users as $options ) {

            $personInfo = new PersonInfo($options);
            $account    = self::$client->getGoogleUser($personInfo);

            if ( $account ) {
                self::$client->deleteGoogleUser($account);        
            }
        }
    }
    
    /**
     * Build in delay between tests to avoid I/O quota issues
     */
    protected function setUp() {
        sleep(1);
    }
    
    protected function getGoogleAdminClient() {
        return self::$client;
    }
    
    public function testPennIDHash() {
        
        $admin = $this->getGoogleAdminClient();
        
        $penn_id = '12345678';
        $penn_id_hash = $admin->getPennIdHash($penn_id);
        
        $this->assertFalse( $penn_id == $penn_id_hash );
        $this->assertRegExp('/^[a-f0-9]{32}$/', $penn_id_hash);
    }
        

    /**
     * Do our test accounts exist?
     * @depends testPennIDHash
     */
    public function testAccountQueries() {
        
        $penn_id = '12345678';
        $pennkey = 'pennkey';
        
        $admin = $this->getGoogleAdminClient();
        
        // bogus account
        $options    = array('penn_id' => '00112233',
                            'pennkey' => 'bogusAccount');
        $personInfo = new PersonInfo($options);
        $account    = $admin->getGoogleUser($personInfo);
        
        $this->assertFalse((boolean) $account);
        
        // account based on pennkey
        $options    = array('pennkey' => 'unittest');
        $personInfo = new PersonInfo($options);
        $account    = $admin->getGoogleUser($personInfo);

        $expected = "unittest@" . self::$params['domain'];
        
        $this->assertEquals('SAS\IRAD\GoogleAdminClientBundle\Service\GoogleUser', get_class($account));
        $this->assertEquals($expected,  $account->getUserId(),   "The Google account 'unittest' needs to exist for testing.");
        $this->assertEquals('unittest', $account->getUsername());

        // account based on penn_id hash
        $options    = array('penn_id' => '99999999');
        $personInfo = new PersonInfo($options);
        $account    = $admin->getGoogleUser($personInfo);
        
        $expected = $admin->getPennIdHash($options['penn_id']) . "@" . self::$params['domain'];
        
        $this->assertEquals($expected, $account->getUserId(), "The Google account based on hashed penn_id '99999999' needs to exist for testing.");
        
        // account based on penn_id hash with both penn_id and pennkey available
        $options    = array('penn_id' => '99999999',
                            'pennkey' => 'boguskey');
        $personInfo = new PersonInfo($options);
        $account    = $admin->getGoogleUser($personInfo);
        
        $expected = $admin->getPennIdHash($options['penn_id']) . "@" . self::$params['domain'];
        
        $this->assertEquals($expected, $account->getUserId());        
    }
    
    /**
     * Test account creation
     * @depends testAccountQueries
     */
    public function testAccountCreation() {
    
        // create a test account
        $options    = array('penn_id'    => '00112233',
                            'pennkey'    => 'test1',
                            'first_name' => 'Test1',
                            'last_name'  => 'User');
        $personInfo = new PersonInfo($options);

        try {
            $admin = $this->getGoogleAdminClient();
            $admin->createGoogleUser($personInfo, sha1('randomBogusPassword'));
        } catch (\Exception $e) {
            $this->fail("Error creating test account: " . $e->getMessage());
        }
        
        // retrieve the account
        $account  = $admin->getGoogleUser($personInfo);
        $expected = "test1@" . self::$params['domain'];
    
        $this->assertTrue((boolean) $account, "The Google account 'test1' was not created.");
        $this->assertEquals($expected, $account->getUserId());
        $this->assertEquals($options['first_name'], $account->getFirstName());
        $this->assertEquals($options['last_name'],  $account->getLastName());
        
        // account creation time should be a fairly recent unix timestamp
        $this->assertTrue( time() - $account->getCreationTime() < 10 );
        
        $this->assertFalse($account->isPennIdHash());
    }    

    
    /**
     * Test account deletion
     * @depends testAccountQueries
     * @depends testAccountCreation
     */
    public function testAccountDeletion() {
    
        // create a test account
        $options    = array('penn_id' => '00112233',
                            'pennkey' => 'test1');
        $personInfo = new PersonInfo($options);

        $admin   = $this->getGoogleAdminClient();
        $account = $admin->getGoogleUser($personInfo);
        
        try {
            $admin->deleteGoogleUser($account);
        } catch (\Exception $e) {
            $this->fail("Error deleting test account: " . $e->getMessage());
        }
    
        // retrieve the account
        $account = $admin->getGoogleUser($personInfo);
        $this->assertFalse($account, "The Google account 'test1' was not deleted.");
    }    
    
    /**
     * If no pennkey is given, an account should be created from the penn_id
     * @depends testAccountQueries
     * @depends testAccountCreation
     * @depends testAccountDeletion
     */
    public function testAccountByPennId() {
        
        // create a test account
        $options    = array('penn_id'    => '00112233',
                            'first_name' => 'Test1',
                            'last_name'  => 'User');
        $personInfo = new PersonInfo($options);

        try {
            $admin = $this->getGoogleAdminClient();
            $admin->createGoogleUser($personInfo, sha1('randomBogusPassword'));
        } catch (\Exception $e) {
            $this->fail("Error creating test account: " . $e->getMessage());
        }
        
        // retrieve the account
        $account  = $admin->getGoogleUser($personInfo);
        $expected = $admin->getPennIdHash($options['penn_id']) . "@" . self::$params['domain'];
    
        $this->assertEquals($expected, $account->getUserId(), "The Google account '00112233' was not created.");
        $this->assertTrue($account->isPennIdHash());
        
        // cleanup
        $admin->deleteGoogleUser($account);
    }

    

    /**
     * Test account first/last update
     * @depends testAccountQueries
     * @depends testAccountCreation
     * @depends testAccountDeletion
     */
    public function testAccountNameUpdate() {
    
        // create a test account
        $options    = array('penn_id'    => '00112233',
                            'pennkey'    => 'test1',
                            'first_name' => 'Test1',
                            'last_name'  => 'User');
        $personInfo = new PersonInfo($options);

        try {
            $admin = $this->getGoogleAdminClient();
            $admin->createGoogleUser($personInfo, sha1('randomBogusPassword'));
        } catch (\Exception $e) {
            $this->fail("Error creating test account: " . $e->getMessage());
        }
        
        // retrieve the account
        $account  = $admin->getGoogleUser($personInfo);
    
        $this->assertEquals($options['first_name'], $account->getFirstName());
        $this->assertEquals($options['last_name'],  $account->getLastName());

        // change the first/last names
        $name = array('first_name' => 'Changed',
                      'last_name'  => 'Name');
        
        $account->setName($name);
        $account->commit();
        
        // this takes a little time to propagate
        sleep(4);
        
        // retrieve the account
        $account  = $admin->getGoogleUser($personInfo);
        $this->assertEquals($name['first_name'], $account->getFirstName());
        $this->assertEquals($name['last_name'],  $account->getLastName());
        
        // cleanup
        $admin->deleteGoogleUser($account);
    }
    
    
    
    /**
     * Test rename account
     * @depends testAccountQueries
     * @depends testAccountCreation
     * @depends testAccountDeletion
     */
    public function testRenameAccount() {

        // create a test account with penn_id
        $options    = array('penn_id'    => '00112233',
                            'first_name' => 'Test1',
                            'last_name'  => 'User');
        $personInfo = new PersonInfo($options);
        
        try {
            $admin = $this->getGoogleAdminClient();
            $admin->createGoogleUser($personInfo, sha1('randomBogusPassword'));
        } catch (\Exception $e) {
            $this->fail("Error creating test account: " . $e->getMessage());
        }
        
        // add pennkey to personInfo
        $options['pennkey'] = 'test1';
        $personInfo = new PersonInfo($options);
        
        // retrieve the account and rename to pennkey
        $account  = $admin->getGoogleUser($personInfo);
        $account->renameToPennkey();
        
        $account  = $admin->getGoogleUser($personInfo);

        $expected = "test1@" . self::$params['domain'];
        $this->assertEquals($expected, $account->getUserId(), "The Google account was not renamed.");
        
        // cleanup
        $admin->deleteGoogleUser($account);
    }    

    /**
     * Various tests on account readiness and activation
     * @depends testAccountQueries
     * @depends testAccountCreation
     * @depends testAccountDeletion
     */
    public function testActivateAccount() {
    
        // create a test account
        $options = array('penn_id'    => '00112233',
                         'pennkey'    => 'test1',
                         'first_name' => 'Test1',
                         'last_name'  => 'User');
        $personInfo = new PersonInfo($options);
    
        try {
            $admin = $this->getGoogleAdminClient();
            $admin->createGoogleUser($personInfo, sha1('randomBogusPassword'));
        } catch (\Exception $e) {
            $this->fail("Error creating test account: " . $e->getMessage());
        }
    
        // retrieve the account
        $account  = $admin->getGoogleUser($personInfo);
        
        // did we get a result
        $this->assertEquals('SAS\IRAD\GoogleAdminClientBundle\Service\GoogleUser', get_class($account));
        
        // we should be in "bulk" org
        $this->assertEquals("/bulk-created-accounts", $account->getOrgUnitPath());
        $this->assertFalse($account->isActivated());
    
        // accounts are not ready until 24 hours after creation
        $this->assertTrue($account->isAccountPending());
    
        $account->activateAccount(sha1('bogusPassword'));
        $this->assertEquals("/activated-accounts", $account->getOrgUnitPath());
        
        // cleanup
        $admin->deleteGoogleUser($account);
    }    
    
}