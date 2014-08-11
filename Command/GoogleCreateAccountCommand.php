<?php

namespace SAS\IRAD\GoogleAdminClientBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use SAS\IRAD\PersonInfoBundle\PersonInfo\PersonInfo;

class GoogleCreateAccountCommand extends ContainerAwareCommand {
    
    protected function configure() {
        
        $this
            ->setName('google:create-account')
            ->setDescription('Create a google account for the supplied pennkey / penn id')
            ->addOption('penn-id',    null, InputOption::VALUE_REQUIRED, "The user's Penn ID")
            ->addOption('pennkey',    null, InputOption::VALUE_REQUIRED, "The user's Pennkey")
            ->addOption('first-name', null, InputOption::VALUE_REQUIRED, "The user's first name")
            ->addOption('last-name',  null, InputOption::VALUE_REQUIRED, "The user's last name")
            ;
        
        $this->setHelp("Create a Google account with the specified parameters. If the penngroups.web_service_query\n"  .
                       "is defined, then only the penn_id or pennkey is required. The other parameters\n"   .
                       "will be queried from the service. If it is NOT defined, then penn_id, first-name\n" .
                       "and last-name are required.");
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        
        $personInfo = $this->getUserInput($input);

        // how do we want to refer to this person in output? prefer pennkey
        $identifier = ( $personInfo->getPennkey() ?: $personInfo->getPennId() );
        
        // okay we have a valid person and their info. get the gmail admin service
        $admin = $this->getContainer()->get('google_admin_client');
        
        // does a google account already exist?
        $user = $admin->getGoogleUser($personInfo);
        
        if ( $user ) {
            throw new \Exception("An account already exists for: $identifier");
        }
        
        $password_hash = sha1($this->randomPassword());
        
        $admin->createGoogleUser($personInfo, $password_hash);
        $output->writeln("Google account created for: $identifier");
    }
    
    
    /**
     * Decode user input and return a PersonInfo object
     * @param InputInterface $input
     * @throws \Exception
     * @return PersonInfo
     */
    public function getUserInput(InputInterface $input) {
        
        // get user input
        $penn_id    = $input->getOption('penn-id');
        $pennkey    = strtolower($input->getOption('pennkey'));
        $first_name = $input->getOption('first-name');
        $last_name  = $input->getOption('last-name');
        
        // validate penn_id and pennkey input
        if ( $penn_id && !preg_match("/^\d{8}$/", $penn_id) ) {
            throw new \Exception("The penn-id parameter \"$penn_id\" is incorrect.");
        }

        if ( $pennkey && !preg_match("/^[a-z][a-z0-9]{1,15}$/", $pennkey) ) {
            throw new \Exception("The pennkey parameter \"$pennkey\" is incorrect.");
        }
            
        // do we have a penngroups service we can use for lookups?
        try {
            $service = $this->getContainer()->get('penngroups.web_service_query');
        } catch (ServiceNotFoundException $e) {
            $service = false;
        }
        
        // no lookups needed
        if ( $penn_id && $pennkey && $first_name && $last_name ) {
            $options = compact('penn_id', 'pennkey', 'first_name', 'last_name');
            return new PersonInfo($options);
        }
        
        if ( !$service ) {
            // penn_id, first, last are required if no lookup service is available
            if ( $penn_id && $first_name && $last_name ) {
                $options = compact('penn_id', 'pennkey', 'first_name', 'last_name');
                return new PersonInfo($options);
            } else {
                throw new \Exception("Parameters penn-id, first-name and last-name are required when penngroups.web_service_query is not defined.");
            }
        }

        // we need a pennkey or penn_id for lookups
        if ( !$penn_id && !$pennkey ) {
            throw new \Exception("A valid penn-id or pennkey parameter must be specified to lookup user information.");
        }
        
        if ( $penn_id ) {
            $personInfo = $service->findByPennId($penn_id);
        } else {
            $personInfo = $service->findByPennkey($pennkey);
        }
        
        if ( !$personInfo ) {
            if ( $penn_id ) {
                $error = "Penn ID \"$penn_id\" does not map to a known person.";
            } else {
                $error = "Pennkey \"$pennkey\" does not map to a known person.";
            }
            throw new \Exception($error);
        }
        
        // make sure specified penn_id and pennkey match lookup results
        if ( $penn_id && $penn_id != $personInfo->getPennId() ) {
            throw new \Exception("Data mismatch: specified penn-id \"$penn_id\" does not match query result \"{$personInfo->getPennId()}\".");
        }

        if ( $pennkey && $pennkey != $personInfo->getPennkey() ) {
            throw new \Exception("Data mismatch: specified pennkey \"$pennkey\" does not match query result \"{$personInfo->getPennkey()}\".");
        }
        
        
        // override first/last name if specified
        if ( $first_name ) {
            $personInfo->setFirstName($first_name);
        }

        if ( $last_name ) {
            $personInfo->setLastName($last_name);
        }
        
        return $personInfo;
    }
    
    /**
     * Generate a random password for our new account
     * @return string
     */
    function randomPassword() {
        $password = '';
        foreach ( range(1,30) as $i ) {
            $password .= chr(rand(33, 126));
        }
        return $password;
    }
    
    
}