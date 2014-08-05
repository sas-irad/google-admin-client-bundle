<?php

namespace SAS\IRAD\GoogleAdminClientBundle\Service;


interface PersonInfoInterface {
    
    public function getPennkey();
    public function getPennId();
    public function getFirstName();
    public function getLastName();
    
}