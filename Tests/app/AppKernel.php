<?php

use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Config\Loader\LoaderInterface;

class AppKernel extends Kernel
{
    public function registerBundles()
    {
        $bundles = array(
            new Symfony\Bundle\FrameworkBundle\FrameworkBundle(),
            new Doctrine\Bundle\DoctrineBundle\DoctrineBundle(),                
            new SAS\IRAD\FileStorageBundle\FileStorageBundle(),
            new SAS\IRAD\GmailAccountLogBundle\GmailAccountLogBundle(),
            new SAS\IRAD\GoogleOAuth2TokenBundle\GoogleOAuth2TokenBundle(),
            new SAS\IRAD\GoogleAdminClientBundle\GoogleAdminClientBundle(),
         );

        return $bundles;
    }

    public function registerContainerConfiguration(LoaderInterface $loader)
    {
        $loader->load(__DIR__.'/config/config_'.$this->getEnvironment().'.yml');
    }
}
