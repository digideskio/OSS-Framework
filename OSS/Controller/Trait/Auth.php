<?php

/**
 * OSS Framework
 *
 * This file is part of the "OSS Framework" - a library of tools, utilities and
 * extensions to the Zend Framework V1.x used for PHP application development.
 *
 * Copyright (c) 2007 - 2012, Open Source Solutions Limited, Dublin, Ireland
 * All rights reserved.
 *
 * Open Source Solutions Limited is a company registered in Dublin,
 * Ireland with the Companies Registration Office (#438231). We
 * trade as Open Solutions with registered business name (#329120).
 *
 * Contact: Barry O'Donovan - info (at) opensolutions (dot) ie
 *          http://www.opensolutions.ie/
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 *
 * It is also available through the world-wide-web at this URL:
 *     http://www.opensolutions.ie/licenses/new-bsd
 *
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to info@opensolutions.ie so we can send you a copy immediately.
 *
 * @category   OSS
 * @package    OSS_Controller
 * @copyright  Copyright (c) 2007 - 2012, Open Source Solutions Limited, Dublin, Ireland
 * @license    http://www.opensolutions.ie/licenses/new-bsd New BSD License
 * @link       http://www.opensolutions.ie/ Open Source Solutions Limited
 * @author     Barry O'Donovan <barry@opensolutions.ie>
 * @author     The Skilled Team of PHP Developers at Open Solutions <info@opensolutions.ie>
 */


/**
 * Controller: A generic trait to implement basic functionality in an AuthController
 *
 * @author     Barry O'Donovan <barry@opensolutions.ie>
 * @author     The Skilled Team of PHP Developers at Open Solutions <info@opensolutions.ie>
 * @category   OSS
 * @package    OSS_Controller
 * @copyright  Copyright (c) 2007 - 2012, Open Source Solutions Limited, Dublin, Ireland
 * @license    http://www.opensolutions.ie/licenses/new-bsd New BSD License
 */
trait OSS_Controller_Trait_Auth
{
    public function preDispatch()
    {
        // is this action enabled?
        if( isset( $this->_options['resources']['auth']['oss']['disabled'][ $this->getRequest()->getActionName() ] )
            && $this->_options['resources']['auth']['oss']['disabled'][ $this->getRequest()->getActionName() ]
        )
        {
            $this->addMessage( 'This action has been disabled by configuration.', OSS_Message::ALERT );
            $this->redirectAndEnsureDie( '/auth/login' );
        }
    }
    
    public function indexAction()
    {
        $this->_forward( 'login' );
    }
    
    
    /**
     * A pre-login function allow and pre-login processing / checks.
     *
     * Override if you need to add functionality.
     */
    protected function _preLogin()
    {}
    
    /**
     * Tries to log the user in.
     */
    public function loginAction()
    {
        // do we already have a valid session?
        if( $this->getIdentity() )
            $this->redirectAndEnsureDie( '' );

        // allow for a possible pre-login hook
        $this->_preLogin();
        
        $this->view->form = $form = $this->_getFormLogin();
        
        // Are remember me cookies enabled and do we have a valid remember me cookie?
        $haveCookie = false;
        if( $this->_rememberMeEnabled() && $user = $this->_processRememberMeCookies() )
        {
            // just hijack the form
            $form->getElement( 'username' )->setValue( $user->getUsername() );
            $form->getElement( 'password' )->setValue( $user->getPassword() );
            $form->getElement( 'rememberme' )->setValue( 0 );
            $haveCookie = true;
            $this->getLogger()->debug( _( "{$user->getUsername()} presented a valid cookie for logging in" ) );
            $this->getSessionNamespace()->logged_in_via = "cookie";
        }
        
        if( $haveCookie || ( $this->getRequest()->isPost() && $form->isValid( $_POST ) ) )
        {
            $auth = Zend_Auth::getInstance();
            $authAdapter = $this->_getAuthAdapter( $form->getValue( 'username' ), $form->getValue( 'password' ) );
    
            if( $haveCookie )
                $authAdapter->haveCookie( true );
            
            $result = $auth->authenticate( $authAdapter );
            
            switch( $result->getCode() )
            {
                case Zend_Auth_Result::FAILURE_IDENTITY_NOT_FOUND:
                case Zend_Auth_Result::FAILURE_CREDENTIAL_INVALID:
                    $this->addMessage( _( 'Invalid username or password' ) . '. ' . _( 'Please try again' ) . '.', OSS_Message::ERROR );
                    return false;
                    break;
    
                case Zend_Auth_Result::SUCCESS:
                    $identity = $auth->getIdentity();
                    $user = $identity['user'];
                    
                    $message = null;
                    if( !$this->_postLoginChecks( $auth, $user, $message ) )
                    {
                        if( $haveCookie ) $this->_deleteRememberMeCookie( $user );
                        $auth->clearIdentity();
                        $this->getSessionNamespace()->unsetAll();
                        $this->getLogger()->debug( sprintf( _( "User %s denied access by custom post auth hook" ), $user->getUsername() ) );
                        if( $message != null ) $this->addMessage( $message, OSS_Message::ERROR );
                        $this->redirectAndEnsureDie( 'auth/login' );
                    }
    
                    if( !$haveCookie && $form->getValue( 'rememberme' ) )
                        $this->_setRememberMeCookie( $user );
                    
                    if( !$haveCookie )
                        $this->getSessionNamespace()->logged_in_via = "auth";
                    
                    // record the last login IP address
                    $this->getSessionNamespace()->last_login_from = '';
                    if( method_exists( $user, 'hasPreference' ) )
                    {
                        if( $ip = $user->hasPreference( 'auth.last_login_from' ) )
                        {
                            $this->getSessionNamespace()->last_login_from = $ip;
                            $this->getSessionNamespace()->last_login_at   = $user->getPreference( 'auth.last_login_at' );
                        }
                        $user->setPreference( 'auth.last_login_from', $_SERVER['REMOTE_ADDR'] );
                        $user->setPreference( 'auth.last_login_at',   mktime()                );
                    }
    
                    // set the timeout
                    $this->getSessionNamespace()->timeOfLastAction = mktime();

                    $this->getLogger()->info( sprintf( _( "%s logged in" ), $user->getUsername() ) );
                    
                    $this->getD2EM()->flush();
                    
                    if( isset( $this->getSessionNamespace()->postAuthRedirect ) )
                        $this->_redirect( $this->getSessionNamespace()->postAuthRedirect );
                    else
                        $this->_redirect( '' );
                    break;
    
                default:
                    throw new OSS_Exception( 'Unknown auth response - ' . $result->getCode() );
                    break;
            }
        }
    }
    
    /**
     * Overridable fucntion to perform custom post (successful) login checks (allowing
     * the login to be cancelled).
     *
     * Override this function to add custom code.
     *
     * @param Zend_Auth $auth The authentication object
     * @param \Entities\User $user The user logging in
     * @param $message string A message to be displayed if returning false (cancelling the login)
     * @return bool False to prevent the user from logging in, else true
     */
    protected function _postLoginChecks( $auth, $user, &$message )
    {
        return true;
    }
    
    /**
     * Logs the user out, clears the identity and the session.
     */
    public function logoutAction()
    {
        // $this->view->clearVars();
        // $this->view->config = $this->config;
        
        if( !$this->getAuth()->hasIdentity() )
            $this->_redirect( '' );

        $this->getLogger()->info( "{$this->getUser()->getUsername()} logged out" );
        
        if( $this->_rememberMeEnabled() )
            $this->_deleteRememberMeCookie( $this->getUser() );
        
        $this->getAuth()->clearIdentity();
        $this->getSessionNamespace()->unsetAll();

        $this->addMessage( '<strong>' . _( 'You have been logged out.' ) . '</strong>', OSS_Message::SUCCESS );
        $this->_redirect( '' );
    }
    
    

    /**
     * Asks for the email and a CAPTCHA text, then sends a validation code (link) to the email address,
     * and then redirects to /reset-password
     */
    public function lostPasswordAction()
    {
        $this->view->form = $form = $this->_getFormLostPassword();

        $form->getElement( 'username' )->setValue( $this->_getParam( 'username', "" ) );

        $this->view->useCaptcha = $useCaptcha = isset( $this->_options['resources']['auth']['oss']['lost_password']['use_captcha'] ) && $this->_options['resources']['auth']['oss']['lost_password']['use_captcha'];

        if( $useCaptcha )
        {
            OSS_Form_Captcha::addCaptchaElements( $form );
            $captcha = new OSS_Captcha_Image( 0, 0 );
            $this->view->captchaId = $captcha->generate();
        }
        
        if( $this->getRequest()->isPost() )
        {
            if( $useCaptcha && $this->_getParam( 'requestnewimage', 0 ) )
            {
                unset( $_POST['requestnewimage'] );
                $form->setDefaults( $_POST );
                $form->getElement( 'captchatext' )->setValue( "" );
                return;
            }

            if( $form->isValid( $_POST ) )
            {
                if( $useCaptcha && !OSS_Captcha_Image::_isValid( $this->_getParam( 'captchaid' ), $this->_getParam( 'captchatext', '__' ) ) )
                {
                    $form->getElement( 'captchatext' )
                        ->setValue( '' )
                        ->addError( 'The entered text does not match that of the image' );
                    return;
                }

                $user = $this->getD2EM()->getRepository(
                                $this->getOptions()['resources']['auth']['oss']['entity'] )
                        ->findOneByUsername( $form->getValue( 'username') );

                if( !$user )
                {
                    $this->addMessage(
                        'If your username was correct, then an email with a key to allow you to change your password below has been sent to you.',
                        OSS_Message::SUCCESS
                    );
                    $this->redirectAndEnsureDie( 'auth/reset-password/un/' . urlencode( $form->getValue( 'username' ) ) );
                }

                // start by removing expired preferences
                if( $user->cleanExpiredPreferences() )
                    $this->getEntityManager()->flush();
                
                $pwdResetToken = OSS_String::random( 40 );
                
                try
                {
                    $user->addIndexedPreference( 'tokens.password_reset', $pwdResetToken, '=', time() + 2*60*60, 5 );
                }
                catch( OSS_Doctrine2_WithPreferences_IndexLimitException $e )
                {
                    $this->addMessage(
                        'The limit of password reset tokens has been reached. Please try again later when the existing ones will expire or contact support.',
                        OSS_Message::ERROR
                    );
                    $this->redirectAndEnsureDie( 'auth/lost-password' );
                }
                
                $this->getEntityManager()->flush();

                $this->view->user  = $user;
                $this->view->token = $pwdResetToken;

                $mailer = $this->getMailer();
                $mailer->setFrom( $this->getOptions()['identity']['mailer']['email'], $this->getOptions()['identity']['mailer']['name'] );
                $mailer->addTo( $user->getEmail(), $user->getFormattedName() );
                $mailer->setSubject( $this->getOptions()['identity']['sitename'] . ' - Password Reset Information' );
                $mailer->setBodyHtml( $this->view->render( 'auth/email/html/lost-password.phtml' ) );
                $mailer->send();

                $this->addMessage(
                    'If your username was correct, then an email with a key to allow you to change your password below has been sent to you.',
                    OSS_Message::SUCCESS
                );
                
                $this->getLogger()->info( sprintf( _( "%s requested a reset password token" ), $user->getUsername() ) );
                
                $this->_redirect( 'auth/reset-password/username/' . urlencode( $form->getValue( 'username' ) ) );
            }
        }
    }

    /*
     * You get here from either lostPasswordAction or from the email using the link. It asks for the email address,
     * the key (token), and the new password. If all fine then sets the new password and redirects to /login
     */
    public function resetPasswordAction()
    {
        $this->view->form = $form = $this->_getFormResetPassword();
        $form->getElement( 'username' )->setValue( $this->_getParam( "username", "" ) );

        if( $this->getRequest()->isPost() && $form->isValid( $_POST ) )
        {
            $user = $this->getD2EM()->getRepository(
                            $this->getOptions()['resources']['auth']['oss']['entity'] )
                    ->findOneByUsername( $form->getValue( 'username') );
            
            if( !$user )
            {
                $this->addMessage(
                    'Invalid username / token combination. Please check your details and try again.',
                    OSS_Message::SUCCESS
                );
            }
            else
            {
                // start by removing expired preferences
                if( $user->cleanExpiredPreferences() )
                    $this->getD2EM()->flush();

                if( !in_array( $form->getValue( 'token' ), $user->getIndexedPreference( 'tokens.password_reset' ) ) )
                {
                    $this->addMessage(
                        'Invalid username / token combination. Please check your details and try again.',
                        OSS_Message::ERROR
                    );
                }
                else
                {
                    $user->setPassword( OSS_Auth_Password::hash( $form->getValue( 'password' ), $this->_options['resources']['auth']['oss'] ) );
                    $user->deletePreference( 'tokens.password_reset' );
                    
                    if( method_exists( $user, 'setFailedLogins' ) )
                        $user->setFailedLogins( 0 );
                    
                    $this->getD2EM()->flush();
                    
                    $this->view->user = $user;

                    $mailer = $this->getMailer();
                    $mailer->setFrom( $this->_options['identity']['mailer']['email'], $this->_options['identity']['mailer']['name'] );
                    $mailer->addTo( $user->getEmail(), $user->getFormattedName() );
                    $mailer->setSubject( $this->_options['identity']['sitename'] . ' - Your Password Has Been Reset' );
                    $mailer->setBodyHtml( $this->view->render( 'auth/email/html/reset-password.phtml' ) );
                    $mailer->send();

                    $this->addMessage(
                        'Your password has been successfully changed. Please log in below with your new password.',
                        OSS_Message::SUCCESS
                    );

                    $this->getLogger()->info( sprintf( _( "%s has completed a password reset" ), $user->getUsername() ) );
                    
                    $this->_redirect( 'auth/login' );
                }
            }
        }
        else
        {
            $form->getElement( 'username' )->setValue( $this->_getParam( 'username',    '' ) );
            $form->getElement( 'token'    )->setValue( $this->_getParam( 'token',       '' ) );
        }
    }

    
    public function lostUsernameAction()
    {
        $this->view->form = $form = $this->_getFormLostUsername();
        
        $this->view->useCaptcha = $useCaptcha = isset( $this->_options['resources']['auth']['oss']['lost_username']['use_captcha'] ) && $this->_options['resources']['auth']['oss']['lost_username']['use_captcha'];

        if( $useCaptcha )
        {
            OSS_Form_Captcha::addCaptchaElements( $form );
            $captcha = new OSS_Captcha_Image( 0, 0 );
            $this->view->captchaId = $captcha->generate();
        }
        
        if( $this->getRequest()->isPost() )
        {
            if( $useCaptcha && $this->_getParam( 'requestnewimage', 0 ) )
            {
                unset( $_POST['requestnewimage'] );
                $form->setDefaults( $_POST );
                $form->getElement( 'captchatext' )->setValue( "" );
                return;
            }
            
            if( $form->isValid( $_POST ) )
            {
                if( $useCaptcha && !OSS_Captcha_Image::_isValid( $this->_getParam( 'captchaid' ), $this->_getParam( 'captchatext', '__' ) ) )
                {
                    $form->getElement( 'captchatext' )
                    ->setValue( '' )
                    ->addError( 'The entered text does not match that of the image' );
                    return;
                }
            
                $this->view->users = $users = $this->getD2EM()->getRepository(
                                $this->getOptions()['resources']['auth']['oss']['entity'] )
                        ->findByEmail( $form->getValue( 'email' ) );
    
                if( count( $users ) )
                {
                    $mailer = $this->getMailer();
                    $mailer->setFrom( $this->_options['identity']['mailer']['email'], $this->_options['identity']['mailer']['name'] );
                    $mailer->addTo( $form->getValue( 'email' ) );
                    $mailer->setSubject( $this->_options['identity']['sitename'] . ' - Your Accounts' );
                    $mailer->setBodyHtml( $this->view->render( 'auth/email/html/lost-username.phtml' ) );
                    $mailer->send();
                }
                            
                $this->addMessage(
                    'If your email matches user(s) on the system, then an email listing those users has been sent to you.',
                    OSS_Message::SUCCESS
                );

                $this->getLogger()->info( sprintf( _( "%s requested lost usernames by email" ), $form->getValue( 'email' ) ) );
                
                $this->_redirect( 'auth/login' );
            }
        }
    }
    
    
    
    /**
     * Takes an id as a parameter, and prints the matching CAPTCHA image as a binary raw string to the output,
     * or prints nothing if no matching CAPTCHA found.
     */
    public function captchaImageAction()
    {
        $captchaId = preg_replace( "/[^0-9a-z]+/u", '', strtolower( basename( $this->_getParam( 'id' ) ) ) );
        $captchaFile = OSS_Utils::getTempDir() . "/captchas/{$captchaId}.png";
        
        if( @file_exists( $captchaFile ) )
        {
            Zend_Controller_Action_HelperBroker::removeHelper( 'viewRenderer' );
            @ob_end_clean();
            header( 'Content-type: image/png' );
            @readfile( $captchaFile );
        }
        else
            $this->_forward( 'error-404', 'error' );
    }
    
    
    
    /**
     * Switch the logged in user to another.
     *
     * Allows administrators to switch to another user and operate as them temporarily.
     */
    public function switchUserAction()
    {
        if( !$this->getAuth()->hasIdentity() )
            $this->_redirect( 'auth/login' );
        
        if( isset( $this->getSessionNamespace()->switched_user_from ) && $this->getSessionNamespace()->switched_user_from )
        {
            $this->addMessage(
                'You are already acting as a substituted user. Please switch back first.',
                OSS_Message::ERROR
            );
            $this->redirectAndEnsureDie( '' );
        }
        
        if( !$this->_switchUserPreCheck() )
            $this->redirectAndEnsureDie( '' );
    
        // does the requested user exist
        $nuser = $this->getD2EM()->getRepository(
                $this->getOptions()['resources']['auth']['oss']['entity']
            )->find( $this->_getParam( 'id', 0 ) );
        
        if( !$nuser )
        {
            $this->addMessage(
                'Invalid user in switch user request. Please check your details and try again.',
                OSS_Message::ERROR
            );
            $this->redirectAndEnsureDie( 'user/list' );
        }
        
        if( !$this->_switchUserCheck( $nuser ) )
            $this->redirectAndEnsureDie( '' );
        
        // store the fact that we're switching in the session
        $this->getSessionNamespace()->switched_user_from = $this->getUser()->getId();
    
        // easiest way to switch users is to just re-autenticate as the new one
        // This maintains consistancy with Zend_Auth and future changes
        $result = $this->_reauthenticate( $nuser );
    
        if( $result->getCode() == Zend_Auth_Result::SUCCESS )
        {
            $this->getLogger()->info( 'User ' . $this->getUser()->getUsername() . ' has switched to user '
                . $nuser->getUsername()
            );
    
            $this->addMessage(
                "You are now logged in as {$nuser->getUsername()} of {$nuser->getCustomer()->getName()}.",
                OSS_Message::SUCCESS
            );
        }
        else
        {
            $this->getLogger()->notice( 'User ' . $this->getUser()->getUsername() . ' has failed to switch to user ' . $nuser->getUsername() );
            $this->forward( 'logout' ); die();
        }
    
        $this->_redirect( '' );
    }
    
    /**
     * Switch back to the original user when switched to another.
     *
     * Allows administrators to switch back from another user who they operated as them temporarily.
     */
    public function switchUserBackAction()
    {
        if( !$this->getAuth()->hasIdentity() )
            $this->_redirect( 'auth/login' );
        
        // are we really operating as another?
        if( !isset( $this->getSessionNamespace()->switched_user_from ) or !$this->getSessionNamespace()->switched_user_from )
        {
            $this->addMessage(
                'You are not currently logged in as another user. You are logged in as: ' . $this->getUser()->getUsername(),
                OSS_Message::ERROR
            );
            $this->redirectAndEnsureDie( '' );
        }
    
        // does the original user exist
        $ouser = $this->getD2EM()->getRepository(
                $this->getOptions()['resources']['auth']['oss']['entity']
            )->find( $this->getSessionNamespace()->switched_user_from );
        
        if( !$ouser )
        {
            $this->forward( 'logout' ); die();
        }
        
        if( !( $params = $this->_switchUserBackCheck( $this->getUser(), $ouser ) ) )
            $this->redirectAndEnsureDie();

        // easiest way to switch users is to just re-autenticate as the new one
        // This maintains consistancy with Zend_Auth and future changes
        $result = $this->_reauthenticate( $ouser );
    
        if( $result->getCode() == Zend_Auth_Result::SUCCESS )
        {
            $this->getLogger()->info( 'User ' . $ouser->getUsername() . ' has switched back from user ' . $this->getUser()->getUsername() );
            $this->addMessage( "You are now logged in as {$ouser->getUsername()}.", OSS_Message::SUCCESS  );
        }
        else
            throw new OSS_Exception( "Error for user {$ouser->getUsername()} switching back from user {$this->getUser()->getUsername()}" );
            
        $this->getSessionNamespace()->switched_user_from = 0;
        
        $this->_redirect( isset( $params['url'] ) ? $params['url'] : '' );
    }
            
    /**
     * A simple function to reauthenticate to a given user **Ignores password**.
     *
     * @param \Entities\User $nuser The user to reauthenticate as
     * @return Zend_Auth_Result
     */
    protected function _reauthenticate( $nuser )
    {
        $auth = Zend_Auth::getInstance();
        $authAdapter = $this->_getAuthAdapter( $nuser->getUsername(), $nuser->getPassword() );
        
        // trick the adapter into ignoring the password
        $authAdapter->haveCookie( true );
        
        return $auth->authenticate( $authAdapter );
    }
    
    
    /**
     * Instantiate the appropriate Zend Auth Adapter
     *
     * @param string $un The username
     * @param string $pw The password
     * @return OSS_Auth_Doctrine2Adapter An appropriate Zend auth adapter (not necessarily `OSS_Auth_Doctrine2Adapter`
     */
    protected function _getAuthAdapter( $un, $pw )
    {
        switch( $this->getOptions()['resources']['auth']['oss']['adapter'] )
        {
            case 'OSS_Auth_DoctrineAdapter':
                $authAdapter = new OSS_Auth_DoctrineAdapter( $un, $pw );
                break;
        
            case 'OSS_Auth_Doctrine2Adapter':
                $authAdapter = new OSS_Auth_Doctrine2Adapter(
                    $un, $pw, $this->getOptions()['resources']['auth']['oss']['entity'],
                    $this->getD2EM(), $this->getOptions()['resources']['auth']['oss']
                );
                break;
        
            default:
                throw new OSS_Exception( 'No such authentication adapter - ' . $this->getOptions()['resources']['auth']['oss']['adapter'] );
        }

        return $authAdapter;
    }
    
    /**
     * This function is called just before `switchUserAction()` processes anything.
     * By default it will stop `switchUserAction()` and redirect with an error (that
     * should be added in this function via `addMessage()`.
     *
     * Feel free to use you own `redirectAndEnsureDie()` destination in this function.
     *
     * You should override this function to ensure the user had the requisite privileges
     * to even try and perform a user switch. Use `_switchUserCheck()` for more invasive
     * checks after the requested user object is found and loaded.
     *
     * @return bool True unless you want the switch to fail.
     */
    protected function _switchUserPreCheck()
    {
        $this->getLogger()->notice( 'User ' . $this->getUser()->getUsername() . ' illegally tried to switch to user with ID '
            . $this->_getParam( 'id', '[unknown]' )
        );
        
        $this->addMessage(
            'You are not allowed to switch users! This attempt has been logged and the administrators notified.',
            OSS_Message::ERROR
        );
        
        $this->redirectAndEnsureDie( '' );
    }

    /**
     * This function is called after `switchUserAction()` loads the requested user object.
     * By default it will stop `switchUserAction()` and redirect with an error (that
     * should be added in this function via `addMessage()`.
     *
     * Feel free to use you own `redirectAndEnsureDie()` destination in this function.
     *
     * You should override this function to perform pre-switch checks.
     *
     * @param \Entities\User $nuser The user to switch to
     * @return bool True unless you want the switch to fail.
     */
    protected function _switchUserCheck( $nuser )
    {
        return false;
    }
    
    /**
     * This function is called just before `switchUserBackAction()` actually switches
     * the user back.
     *
     * Feel free to use you own `redirectAndEnsureDie()` destination in this function.
     *
     * You should override this function to perform pre-switch-back checks.
     *
     * You can also return an array of optional parameters to affect the switch back. Right
     * now, these are:
     *
     *     ['url'] => a redirect destination after the switch back is complete rather than ''
     *
     * @param \Entities\User $subUser The current user we have switched to (substituted to)
     * @param \Entities\User $origUser The original user that we switched from
     * @return bool|array False if you want the switch back to fail.
     */
    protected function _switchUserBackCheck( $subUser, $origUser )
    {
        return false;
    }
    
    /**
     * Check if remember me cookies are enabled and correctly configured in `application.ini`
     *
     * @return bool True if everything is configured correctly and enabled
     */
    protected function _rememberMeEnabled()
    {
        if( isset( $this->_options['resources']['auth']['oss']['rememberme'] ) )
        {
            $conf = $this->_options['resources']['auth']['oss']['rememberme'];
            
            if( isset( $conf['enabled'] ) && $conf['enabled'] )
            {
                if( !isset( $conf['timeout'] ) || !$conf['timeout'] )
                {
                    $this->getLogger()->err( 'Remember Me cookies enabled but misconfigured: timeout not defined' );
                    return false;
                }
                
                if( !isset( $conf['salt'] ) || !strlen( $conf['salt'] ) )
                {
                    $this->getLogger()->err( 'Remember Me cookies enabled but misconfigured: salt not defined' );
                    return false;
                }
                
                if( !isset( $conf['secure'] ) )
                {
                    $this->getLogger()->err( 'Remember Me cookies enabled but misconfigured: secure not defined' );
                    return false;
                }
                
                return true;
            }
        }
            
        return false;
    }
    
    
    /**
     * Process the user's cookies, if any, for valid 'remember me' authentication
     *
     * This will also update the user's cookie key to ensure it changes everytime it's used
     *
     * @return \Entities\User A user object if we have a valid cookie (otherwise false)
     */
    protected function _processRememberMeCookies()
    {
        if( !isset( $_COOKIE['aval'] ) || !isset( $_COOKIE['bval'] ) || !$_COOKIE['aval'] || !$_COOKIE['bval'] )
            return false;
    
        $cookie = $this->getD2EM()->getRepository( '\\Entities\\RememberMe' )->load( $_COOKIE['aval'], $_COOKIE['bval'] );
    
        if( !$cookie )
            return false;
    
        $user = $cookie->getUser();
    
        if( $cookie->getExpires() < new DateTime() )
        {
            $user->getRememberMes()->removeElement( $cookie );
            $this->getEntityManager()->remove( $cookie );
            $this->getEntityManager()->flush();
            return false;
        }
    
        // we have a valid combination. Update the user's cookies
        $this->_setRememberMeCookie( $user, $cookie );
    
        return $user;
    }
    
    
    
    /**
     * Set cookies for Remember Me functionality
     *
     * The username is stored as a salted SHA1 hashed value to protect the user's username
     * The key is a random 40 charater string
     *
     * @param \Entitues\User $user The user enitiy
     * @param \Entities\RememberMe $rememberme The remember me entity with cookie details (or null to create one)
     */
    protected function _setRememberMeCookie( $user, $rememberme = null )
    {
        if( $rememberme == null )
        {
            $rememberme = new \Entities\RememberMe();
            $rememberme->setUser( $user );
            $rememberme->setCreated( new DateTime() );
            $rememberme->setOriginalIp( $_SERVER['REMOTE_ADDR'] );
            $rememberme->setUserhash( $this->_generateCookieUserhash( $user ) );
            $this->getD2EM()->persist( $rememberme );
        }
    
        $expire = time() + $this->_options['resources']['auth']['oss']['rememberme']['timeout'];
    
        $rememberme->setExpires( new DateTime( "@{$expire}" ) );
        $rememberme->setLastUsed( new DateTime() );
        $rememberme->setCkey( OSS_String::random( 40, true, true, true, '', '' ) );
    
        $this->getD2EM()->flush();
    
        setcookie( 'aval', $rememberme->getUserhash(),  $expire, '/', '', $this->_options['resources']['auth']['oss']['rememberme']['secure'], true );
        setcookie( 'bval', $rememberme->getCkey(),      $expire, '/', '', $this->_options['resources']['auth']['oss']['rememberme']['secure'], true );
    }
    
    
    /**
     * Generate the user hash in a secure format for storage in a client side 'remember cookie' cookie
     *
     * @param \Entities\User $user The user entitiy to generate the hash for
     * @return string The `sha1()` hash
     */
    protected function _generateCookieUserhash( $user )
    {
        return sha1( $user->getId() . '+' . $user->getUsername() . '/' . $this->_options['resources']['auth']['oss']['rememberme']['salt'] );
    }
    
    
    
    /**
     * Delete all stored RememberMe cookies for a user (server and client side)
     *
     * @param \Entities\User $user The user entity
     * @return int The number of remember mes deleted from the DB (or 0 if none). Can be safely ignored.
     */
    protected function _deleteRememberMeCookie( $user )
    {
        setcookie( 'aval', '', time() - 100000, '/', '', $this->_options['resources']['auth']['oss']['rememberme']['secure'], true );
        setcookie( 'bval', '', time() - 100000, '/', '', $this->_options['resources']['auth']['oss']['rememberme']['secure'], true );
        
        return $this->getD2EM()->getRepository( '\\Entities\\RememberMe' )->deleteForUser( $user );
    }
    
    
    
    /**
     * It is the main login action, but if the user is using One Time Code or Yubikey to log in, then it is just the first step.
     * If the user is using One Time Code, then redirects to auth/send-one-time-code
     * If the user is using Yubikey, then redirects to auth/yubikey
     * If the user is not using any additional security, then calls $this->_doLogin()
     */
    public function loginSecureAction()
    {
        if( $this->getIdentity() )
        {
            $this->addMessage( 'You are already logged in.', OSS_Message::INFO );
            $this->_redirect( 'user' );
        }

        $auth = Zend_Auth::getInstance();

        $this->view->form = $form = new OSSPayroll_Form_Auth_LoginSecure();
        $form->getElement( 'email' )->setValue( $this->_getParam( 'email', "" ) );

        $captcha = new OSS_Captcha_Image( 0, 0 );
        $this->view->captchaId = $captcha->generate();


        if( $this->getRequest()->isPost() )
        {
            if( $this->_getParam( 'requestnewimage', 0 ) )
            {
                unset( $_POST['requestnewimage'] );
                $this->view->form->setDefaults( $_POST );
                $form->getElement( 'captchatext' )->setValue( "" );
                return;
            }

            if( $form->isValid( $_POST ) )
            {
                if( !OSS_Captcha_Image::_isValid( $this->_getParam( 'captchaid' ), $this->_getParam( 'captchatext', '__' ) ) )
                {
                    $form->getElement( 'captchatext' )
                        ->setValue( '' )
                        ->addError( 'The entered text does not match that of the image' );
                    return;
                }

                $this->_forward( "login" );
            }

        }

        $form->getElement( 'captchatext' )->setValue( "" );
    }

    /**
     * The check failed logins
     *
     * This functions is called after authorisation.
     *
     * It checks how many bad attempts had this username and redirects user to login with captcha,
     * to lost password page, back to login page, or do nothing if was auth was successful and captcha was
     * provided.
     *
     * @param int $count  The count of bad login attempts
     * @param string $username The username
     * @param bool $success default false If function called after success authorisation
     * @return bool Always true as all other actions are _redirect()
     */
    protected function _checkFailedLogins( $count, $username, $success = false )
    {
        if( $count >= $this->_options['login_security']['failed_logins']['locked_after'] )
        {
            $this->_auth->clearIdentity();
            if( $this->_session ) $this->getSessionNamespace()->unsetAll();
            
            $this->addMessage(  'Due to an excessive amount of failed logins, '
                . 'your account has been locked for your own security. '
                . 'To unlock you account, please follow the <em>Lost Password</em> '
                . 'procedure below which will guide you through setting a new password '
                . 'and unlocking your account.', OSS_Message::ERROR
            );
            
            $this->redirectAndEnsureDie( "auth/lost-password/email/" . urlencode( $username ) );
        }
        else if( ( !$success || !isset( $_POST['captchatext'] ) ) && $count >= $this->_options['login_security']['failed_logins']['captcha_after'] )
        {
            $this->_auth->clearIdentity();
            if( $this->_session ) $this->getSessionNamespace()->unsetAll();
            
            $this->addMessage( 'While the password you provided may have been correct, due '
                . 'to an excessive number of previous failed login attempts on your account, '
                . 'we require you to login in using the form below.', OSS_Message::ERROR
            );
            
            $this->redirectAndEnsureDie( "auth/login-secure/email/" . urlencode( $username ) );
        }
        else if( !$success )
        {
            $this->_auth->clearIdentity();
            if( $this->_session ) $this->getSessionNamespace()->unsetAll();
            
            $this->addMessage( '<strong>Login failed!</strong> Invalid username / password combination', OSS_Message::ERROR );
            
            $this->redirectAndEnsureDie( "auth/login/email/" . urlencode( $username ) );
        }
        
        return true;
    }

    /**
     * The get failed login by username object including failed count and lastseen time.
     *
     * This functions is called only then user was not found in database.
     *
     * It checks if this username was used before if not creates an etnry and set default values.
     *
     * Otherwise, if the time interval is greater than the timeout, it resets the counters,
     * or if not, it increases the counter.
     *
     * @link https://dev.opensolutions.ie/jira/browse/EPAYROLL-11
     *
     * We need to deal with brute force attempts on unknown / multiple accounts.
     *
     * We use the FailedLoginsByUsername database table which includes:
     *
     *     username - (unique) the (possibly unknown) username presented
     *     count    - the failed login count
     *     lastseen - when it was last seen.
     *
     * What happens here is:
     *
     * * (1) If the presented login username is a valid user, then we never refer to the Failed_Logins_By_Username table;
     * * If the presented login username is not a valid user:
     * ** if the username does not exist in the Failed_Logins_By_Username table, add it with
     *    a count of 1 and set the lastseen to now;
     * ** if the username does exist in the Failed_Logins_By_Username table and the lastseen
     *    value is >86400 seconds ago, reset the count to 1 and set the lastseen to now;
     * ** if the username does exist in the Failed_Logins_By_Username table and the lastseen
     *    value is <86400 seconds ago, increment the count and proceed as if it were a real user (i.e. present a captcha after 3 and a locked account message after 6).
     *
     * Note that because of (1) above, entries in the Failed_Logins_By_Username table will
     * not affect users subsequently signing up.
     *
     * @param string $username The username presented for login
     * @return \Entities\FailedLoginsByUsername
     */
    protected function _getFailedLoginByUsername( $username )
    {
        $buser =  $this->getEntityManager()->getRepository( '\Entities\FailedLoginsByUsername' )
                    ->findOneBy( array( 'username' => $username ) );
            
        if( !$buser )
        {
            $buser = new \Entities\FailedLoginsByUsername();
            $buser->setLastseen( new \DateTime() );
            $buser->setCount( 1 );
            $buser->setUsername( $username);
            $this->getEntityManager()->persist( $buser );
        }
        else
        {
            $lastseen = $buser->getLastseen()->format( 'U' );

            if( ( time() - $lastseen ) > $this->_options['login_security']['failed_logins_by_username']['timeout'] )
            {
                $buser->setLastseen( new \DateTime() );
                $buser->setCount( 1 );
            }
            else
                $buser->setCount( $buser->getCount() + 1 );
        }
        
        $this->getEntityManager()->flush();

        return $buser;
    }


    /**
     * Restricting Brute Force Logins by IP
     *
     * We'll use a sliding window to count the logins over a short period of time and
     * in aggregate over a longer period of time.
     *
     * The database table has the following columns:
     *
     *      ip           - (unique) the source IP address - big enough to hold uncompressed IPv6 addresses
     *      count        - the failed login count
     *      lastseen     - when it was last seen
     *      clear_count  - the number of times the count was cleared
     *      last_cleared - when the count was last cleared
     *
     * And configuration parameters:
     *
     *     login_security.failed_logins_by_ip.timeout = 300
     *     login_security.failed_logins_by_ip.block_after = 10
     *     login_security.failed_logins_by_ip.timeout2 = 3600
     *     login_security.failed_logins_by_ip.block_after2 = 1
     *
     *
     * When any login is received (and before checking the username or password):
     *
     *  * if the source IP address does exist in Failed_Logins_By_Ip
     *    and lastseen >= now - timeout and count >= block_after; or
     *  * if the source IP address does exist in Failed_Logins_By_Ip
     *    and count >= block_after2 and last_cleared >= now - timeout2 and clear_count >= 1; then
     *
     *  ** increment the count;
     *  ** set lastseen to now;
     *  ** block login and inform the user.
     *
     * @see https://dev.opensolutions.ie/jira/browse/EPAYROLL-12
     *
     * @return \Entities\FailedLoginsByIp
     */
    protected function _initialIpCheck()
    {
        $ipcheck = $this->getEntityManager()->getRepository( '\Entities\FailedLoginsByIp' )
                        ->findOneBy( array( 'ip' => $_SERVER['REMOTE_ADDR'] ) );

        if( $ipcheck )
        {
            $lastseen    = $ipcheck->getLastseen()->format( 'U' );
            $lastcleared = $ipcheck->getLastCleared()->format( 'U' );
            $now         = time();

            if(
                ( ( $now - $lastseen ) < $this->_options['login_security']['failed_logins_by_ip']['timeout']
                     && $ipcheck->getCount() >= $this->_options['login_security']['failed_logins_by_ip']['block_after']
                )
                ||
                ( ( $now - $lastcleared ) < $this->_options['login_security']['failed_logins_by_ip']['timeout2']
                    && $ipcheck->getCount() >= $this->_options['login_security']['failed_logins_by_ip']['block_after2']
                    && $ipcheck->getClearCount() >= 1
                ) )
            {
                $ipcheck->setCount( 1 );
                $ipcheck->setLastseen( new \DateTime() );
                $this->getEntityManager()->flush();

                $this->addMessage( "Due to an excessive amount of login attempts from your IP address, we are temporarily blocking further logins for security. Please try again after an hour has passed.", OSS_Message::ERROR );
                $this->_redirect( '' );
                die( 'Unexpected fall through from redirect()' );
            }
        }

        return $ipcheck;
    }

    /**
     * The IP check function to rate limit / block brute force attempts
     *
     * This executes only if the login attempt was bad. It checks if the IP was
     * seen before and if not it creates an entry in FaildeLoginsByIp.
     *
     * If we have seen it before, it cheks if last bad attempt was later than
     * the time out and clears counter if it was.
     *
     * Otherwise, it increments the failed login count.
     *
     * @param \Entities\FailedLoginsByIp $ipcheck Object wich stores failed logins by ip data.
     * @return bool Always true as all other actions issue a _redirect().
     */
    protected function _ipCheck( $ipcheck )
    {
        if( !$ipcheck )
        {
            $ipcheck = new \Entities\FailedLoginsByIp();
            $ipcheck->setIp( $_SERVER['REMOTE_ADDR'] );
            $ipcheck->setCount( 1 );
            $ipcheck->setLastseen( new \DateTime() );
            $ipcheck->setClearCount( 0 );
            $ipcheck->setLastCleared( new \DateTime() );
            
            $this->getEntityManager()->persist( $ipcheck );
        }
        else
        {
            $lastseen = $ipcheck->getLastseen()->format( 'U' );
            $now      = time();

            if( ( $now - $lastseen ) >= $this->_options['login_security']['failed_logins_by_ip']['timeout'] )
            {
                $ipcheck->setCount( 1 );
                $ipcheck->setLastseen( new \DateTime() );
                $ipcheck->setClearCount( $ipcheck->getClearCount() + 1 );
                $ipcheck->setLastCleared( new \DateTime() );
            }
            else if( ( $now - $lastseen ) < $this->_options['login_security']['failed_logins_by_ip']['timeout'] && $ipcheck->getCount() < $this->_options['login_security']['failed_logins_by_ip']['block_after'] )
            {
                $ipcheck->setCount( $ipcheck->getCount() + 1 );
                $ipcheck->setLastseen( new \DateTime() );
            }
        }
        
        $this->getEntityManager()->flush();
        return true;
    }

}
