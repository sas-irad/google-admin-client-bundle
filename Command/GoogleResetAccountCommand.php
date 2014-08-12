<?php

namespace SAS\IRAD\GoogleAdminClientBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use SAS\IRAD\PersonInfoBundle\PersonInfo\PersonInfo;

class GoogleResetAccountCommand extends ContainerAwareCommand {
    
    protected function configure() {
        
        $this
            ->setName('google:reset-account')
            ->setDescription('Reset a google account specified by a pennkey / penn id')
            ->addOption('penn-id',    null, InputOption::VALUE_REQUIRED, "The user's Penn ID")
            ->addOption('pennkey',    null, InputOption::VALUE_REQUIRED, "The user's Pennkey")
            ;
        
        $this->setHelp("Reset a Google account back to the bulk-created-accounts OU.");
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        
        $personInfo = $this->getUserInput($input);

        // how do we want to refer to this person in output? prefer pennkey
        $identifier = ( $personInfo->getPennkey() ?: $personInfo->getPennId() );
        
        // okay we have a valid person and their info. get the gmail admin service
        $admin = $this->getContainer()->get('google_admin_client');
        
        // does a google account already exist?
        $user = $admin->getGoogleUser($personInfo);
        
        if ( !$user ) {
            throw new \Exception("No account exists for: $identifier");
        }
        
        $user->setOrgUnit('bulk-created-accounts');
        $output->writeln("Google account reset for: $identifier");
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
                
        if ( $service ) {
            $personInfo = $service->findByPennId($penn_id);
        } else {
            $personInfo = new PersonInfo(compact('penn_id', 'pennkey'));
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
        
        return $personInfo;
    }
}