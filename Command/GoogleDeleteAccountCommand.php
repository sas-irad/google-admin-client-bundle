<?php

namespace SAS\IRAD\GoogleAdminClientBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use SAS\IRAD\GoogleAdminClientBundle\Service\PersonInfo;

class GoogleDeleteAccountCommand extends ContainerAwareCommand {
    
    protected function configure() {
        
        $this
            ->setName('google:delete-account')
            ->setDescription('Delete a google account matching the supplied pennkey / penn id')
            ->addOption('penn-id',    null, InputOption::VALUE_REQUIRED, "The user's Penn ID")
            ->addOption('pennkey',    null, InputOption::VALUE_REQUIRED, "The user's Pennkey")
            ;
        
        $this->setHelp("Delete a Google account by specifying a pennkey or penn_id.");
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        
        $personInfo = $this->getUserInput($input);

        // how do we want to refer to this person in output? prefer pennkey
        $identifier = ( $personInfo->getPennkey() ?: $personInfo->getPennId() );
        
        $admin = $this->getContainer()->get('google_admin_client');
        
        // does a google account already exist?
        $user = $admin->getGoogleUser($personInfo);
        
        if ( !$user ) {
            throw new \Exception("No account exists for: $identifier");
        }
        
        $admin->deleteGoogleUser($user);
        $output->writeln("Google account for \"$identifier\" deleted.");
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

        if ( !$penn_id && !$pennkey ) {
            throw new \Exception("A valid penn-id or pennkey parameter must be specified.");
        }
        
        $options = compact('penn_id', 'pennkey');
        $personInfo = new PersonInfo($options);
        
        if ( $pennkey && $penn_id ) {
            return $personInfo;
        }
        
        // do we have a person_info_service we can use for lookups?
        try {
            $service = $this->getContainer()->get('person_info_service');
        } catch (ServiceNotFoundException $e) {
            $service = false;
        }
        
        if ( !$service ) {
            // nothing more we can do
            return $personInfo;
        }
        
        if ( $penn_id ) {
            $personInfo = $service->searchByPennId($penn_id);
        } else {
            $personInfo = $service->searchByPennkey($pennkey);
        }
        
        if ( !$personInfo ) {
            if ( $penn_id ) {
                $error = "Penn ID \"$penn_id\" does not map to a known person.";
            } else {
                $error = "Pennkey \"$pennkey\" does not map to a known person.";
            }
            throw new \Exception($error);
        }
        
        return $personInfo;
    }
}