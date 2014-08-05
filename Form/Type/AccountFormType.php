<?php

namespace SAS\IRAD\GoogleAdminClientBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;


/**
 * Simple form to collect password plus other account options for Google service
 * @author robertom
 */
class AccountFormType extends AbstractType {

    
    public function buildForm(FormBuilderInterface $builder, array $options) {

        /**
         * We need a username field so our password validator can verify the
         * user hasn't typed in the username for a password. This is NOT used to
         * identify the user.
         */
        $builder->add('username', 'hidden',
                array('attr' => array('class' => 'username')));
        
        $builder->add('password1', 'password',
            array('attr' => 
                    array('class'       => 'passwordField password1',
                          'maxlength'   => '40' )));
        
        $builder->add('password2', 'password',
            array('attr' => 
                    array('class'       => 'passwordField password2',
                          'maxlength'   => '40' )));
        
        $builder->add('mail_forwarding', 'checkbox',
            array('required' => false,
                  'attr' =>
                    array('class'    => 'mail-forwarding',
                          'checked'  => 'checked',
                          'value'    => 'YES' )));
    }
    
    public function getName() {
        return 'AccountForm';
    }
    
    public function setDefaultOptions(OptionsResolverInterface $resolver) {
    }
}