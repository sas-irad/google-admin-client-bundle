<?php

namespace SAS\IRAD\GoogleAdminClientBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use SAS\IRAD\PersonInfoBundle\PersonInfo\PersonInfo;

class GoogleRenameAccountCommand extends ContainerAwareCommand {
    
    protected function configure() {
        
        $this
            ->setName('google:rename-account')
            ->setDescription('Rename a google account to a new pennkey')
            ->addOption('penn-id',     null,  InputOption::VALUE_OPTIONAL, "The penn_id for the account")
            ->addOption('old-pennkey', null,  InputOption::VALUE_REQUIRED, "The old pennkey")
            ->addOption('new-pennkey', null,  InputOption::VALUE_REQUIRED, "The new pennkey")
            ->addOption('delete-alias', null, InputOption::VALUE_NONE,     "Flag to delete the default alias from old to new account")
            ;
        
        $this->setHelp("Rename a Google account to a new pennkey. Only.");
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        
        $personInfo  = $this->validateInput($input);
        
        $old_pennkey = $input->getOption('old-pennkey');
        $new_pennkey = $input->getOption('new-pennkey');

        $admin  = $this->getContainer()->get('google_admin_client');
        $logger = $this->getContainer()->get('account_logger');
        
        // does a google account exist for the old pennkey?
        $personInfo->setPennkey($old_pennkey);
        $oldUser = $admin->getGoogleUser($personInfo);

        if ( !$oldUser ) {
            throw new \Exception("A google account for the old pennkey does not exist.");
        }
        
        if ( $oldUser->isPennIdHash() ) {
            throw new \Exception("This account is based on a penn_id hash, not a pennkey. Account will be renamed when user provisions account.");
        }
        
        // does a google account already exist for the new pennkey?
        $personInfo->setPennkey($new_pennkey);
        $newUser = $admin->getGoogleUser($personInfo);
        
        if ( $newUser ) {
            if ( $newUser->getUsername() == $new_pennkey ) {
                throw new \Exception("An account already exists for pennkey: $new_pennkey");
            } else {
                throw new \Exception("The new pennkey \"$new_pennkey\" is an alias for: " . $newUser->getUserId());
            }
        }

        $options = array();
        if ( $input->getOption('delete-alias') ) {
            $options['delete_alias'] = true;
        }

        // rename the account and output the logs
        $admin->renameGoogleUser($oldUser, $new_pennkey, $options);
        $logger->updatePennkey($personInfo->getPennId(), $old_pennkey, $new_pennkey);
        
        $output->writeln("Google account \"$old_pennkey\" renamed to \"$new_pennkey\".");
    }
    
    
    /**
     * Decode user input and return a PersonInfo object
     * @param InputInterface $input
     * @throws \Exception
     * @return PersonInfo
     */
    public function validateInput(InputInterface $input) {
        
        // get user input
        $penn_id = $input->getOption('penn-id');
        $old_pennkey = strtolower($input->getOption('old-pennkey'));
        $new_pennkey = strtolower($input->getOption('new-pennkey'));

        try {
            $service = $this->getContainer()->get('penngroups.ldap_query');
        } catch (ServiceNotFoundException $e) {
            $service = false;
        }
        
        if ( !$penn_id && !$service ) {
            throw new \Exception("A valid penn-id must be specified if no penngroups service is being used.");
        }
        
        // validate penn_id and pennkey input
        if ( $penn_id && !preg_match("/^\d{8}$/", $penn_id) ) {
            throw new \Exception("The penn-id parameter \"$penn_id\" is incorrect.");
        }

        if ( !preg_match("/^[a-z][a-z0-9]{1,15}$/", $old_pennkey) ) {
            throw new \Exception("The old-pennkey parameter \"$old_pennkey\" is incorrect.");
        }

        if ( !preg_match("/^[a-z][a-z0-9]{1,15}$/", $new_pennkey) ) {
            throw new \Exception("The new-pennkey parameter \"$new_pennkey\" is incorrect.");
        }
                
        // do we have a person_info_service we can use for lookups?
        if ( $service ) {
            if ( $penn_id ) {

                $personInfo = $service->findByPennId($penn_id);
                
                if ( !$personInfo ) {
                    throw new \Exception("The given penn_id was not found in penngroups.");
                }
                
                if ( $personInfo->getPennkey() != $old_pennkey && $personInfo->getPennkey() != $new_pennkey ) {
                    throw new \Exception("The given penn_id doesn't match either pennkey.");
                }
            } else {
                
                $personInfo = $service->findByPennkey($new_pennkey);
                
                if ( !$personInfo ) {
                    throw new \Exception("The new pennkey value was not found in penngroups.");
                }
            }
        } else {
            $options = array('penn_id' => $penn_id, 'pennkey' => $old_pennkey);
            $personInfo = new PersonInfo($options);
        }
        
        return $personInfo;
    }
}