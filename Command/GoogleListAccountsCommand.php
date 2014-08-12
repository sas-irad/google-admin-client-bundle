<?php

namespace SAS\IRAD\GoogleAdminClientBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use SAS\IRAD\PersonInfoBundle\PersonInfo\PersonInfo;

class GoogleListAccountsCommand extends ContainerAwareCommand {
    
    protected function configure() {
        
        $this
            ->setName('google:list-accounts')
            ->setDescription('List the google accounts for the current domain.')
            ;
        
        $this->setHelp("List all the google accounts in the current domain");
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        
        $admin = $this->getContainer()->get('google_admin_client');
        $users = $admin->getAllGoogleUsers();
        
        foreach ( $users as $user ) {
            $output->writeln("  $user");
        }
        
    }
 
}