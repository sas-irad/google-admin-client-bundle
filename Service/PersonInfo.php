<?php

namespace SAS\IRAD\GoogleAdminClientBundle\Service;


class PersonInfo implements PersonInfoInterface {
    
    private $pennkey;
    private $penn_id;
    private $first_name;
    private $last_name;
    
    public function __construct($array) {
        
        foreach ( array('pennkey', 'penn_id', 'first_name', 'last_name') as $field ) {
            if ( isset($array[$field]) ) {
                $this->$field = $array[$field];
            }
        }
    }

    public function setPennkey($pennkey) {
        $this->pennkey = $pennkey;
        return $this;
    }    
    
    public function getPennkey() {
        return $this->pennkey;
    }
    
    public function setPennId($penn_id) {
        $this->penn_id = $penn_id;
        return $this;
    }    
    
    public function getPennId() {
        return $this->penn_id;
    }

    public function setFirstName($first_name) {
        $this->first_name = $first_name;
        return $this;
    }    
    
    public function getFirstName() {
        return $this->first_name;
    }    

    public function setLastName($last_name) {
        $this->last_name = $last_name;
        return $this;
    }    
    public function getLastName() {
        return $this->last_name;
    }
    
}