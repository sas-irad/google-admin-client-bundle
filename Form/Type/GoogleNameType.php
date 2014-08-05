<?php

namespace SAS\IRAD\GoogleAdminClientBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;


/**
 * Simple form to collect first/name last for Google account update.
 * @author robertom
 */
class GoogleNameType extends AbstractType {

    
    public function buildForm(FormBuilderInterface $builder, array $options) {
        
        $builder->add('first_name', 'text',
            array('attr' => 
                    array('class'       => 'nameField firstNameField',
                          'maxlength'   => '40' )));
        
        $builder->add('last_name', 'text',
            array('attr' => 
                    array('class'       => 'nameField lastNameField',
                          'maxlength'   => '40' )));
        
    }
    
    public function getName() {
        return 'GoogleName';
    }
    
    
    public function setDefaultOptions(OptionsResolverInterface $resolver) {
    }
    
}