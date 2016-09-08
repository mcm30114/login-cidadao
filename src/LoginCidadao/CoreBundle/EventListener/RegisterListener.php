<?php

namespace LoginCidadao\CoreBundle\EventListener;

use FOS\UserBundle\FOSUserEvents;
use FOS\UserBundle\Event\FormEvent;
use LoginCidadao\CoreBundle\Model\PersonInterface;
use LoginCidadao\CoreBundle\Service\RegisterRequestedScope;
use LoginCidadao\OAuthBundle\Entity\ClientRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use FOS\UserBundle\Util\TokenGeneratorInterface;
use FOS\UserBundle\Mailer\MailerInterface;
use FOS\UserBundle\Event\GetResponseUserEvent;
use FOS\UserBundle\Event\FilterUserResponseEvent;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Translation\TranslatorInterface;
use LoginCidadao\ValidationBundle\Validator\Constraints\UsernameValidator;
use Doctrine\ORM\EntityManager;
use LoginCidadao\CoreBundle\Entity\Authorization;

class RegisterListener implements EventSubscriberInterface
{
    private $router;

    /** @var Session */
    private $session;

    /** @var TranslatorInterface */
    private $translator;

    /** @var MailerInterface */
    private $mailer;

    /** @var TokenGeneratorInterface */
    private $tokenGenerator;

    private $emailUnconfirmedTime;
    protected $em;
    private $lcSupportedScopes;

    /** @var RegisterRequestedScope */
    private $registerRequestedScope;

    /** @var ClientRepository */
    public $clientRepository;

    /** @var string */
    private $defaultClientUid;

    public function __construct(
        UrlGeneratorInterface $router,
        Session $session,
        TranslatorInterface $translator,
        MailerInterface $mailer,
        TokenGeneratorInterface $tokenGenerator,
        RegisterRequestedScope $registerRequestedScope,
        ClientRepository $clientRepository,
        $emailUnconfirmedTime,
        $lcSupportedScopes,
        $defaultClientUid
    ) {
        $this->router = $router;
        $this->session = $session;
        $this->translator = $translator;
        $this->mailer = $mailer;
        $this->tokenGenerator = $tokenGenerator;
        $this->emailUnconfirmedTime = $emailUnconfirmedTime;
        $this->lcSupportedScopes = $lcSupportedScopes;
        $this->registerRequestedScope = $registerRequestedScope;
        $this->clientRepository = $clientRepository;
        $this->defaultClientUid = $defaultClientUid;
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            FOSUserEvents::REGISTRATION_SUCCESS => 'onRegistrationSuccess',
            FOSUserEvents::REGISTRATION_COMPLETED => 'onRegistrationCompleted',
            FOSUserEvents::REGISTRATION_CONFIRM => 'onEmailConfirmed',
        );
    }

    public function onRegistrationSuccess(FormEvent $event)
    {
        $user = $event->getForm()->getData();

        if (null === $user->getConfirmationToken()) {
            $user->setConfirmationToken($this->tokenGenerator->generateToken());
            $user->setEmailExpiration(new \DateTime("+$this->emailUnconfirmedTime"));
        }

        $key = '_security.main.target_path';
        if ($this->session->has($key)) {
            //this is to be catch by loggedinUserListener.php
            return $event->setResponse(new RedirectResponse($this->router->generate('lc_home')));
        }

        $email = explode('@', $user->getEmailCanonical(), 2);
        $username = $email[0];
        if (!UsernameValidator::isUsernameValid($username)) {
            $url = $this->router->generate('lc_update_username');
        } else {
            $url = $this->router->generate('fos_user_profile_edit');
        }
        $event->setResponse(new RedirectResponse($url));
    }

    public function onRegistrationCompleted(FilterUserResponseEvent $event)
    {
        $user = $event->getUser();
        $auth = new Authorization();
        $auth->setPerson($user);
        $auth->setClient($this->clientRepository->findOneBy(['uid' => $this->defaultClientUid]));
        $auth->setScope(explode(' ', $this->lcSupportedScopes));
        $this->em->persist($auth);
        $this->em->flush();

        $this->mailer->sendConfirmationEmailMessage($user);

        if (strlen($user->getPassword()) == 0) {
            // TODO: DEPRECATE NOTIFICATIONS
            // TODO: create an optional task offering users to set a password
            //$this->notificationsHelper->enforceEmptyPasswordNotification($user);
        }

        $this->registerRequestedScope->clearRequestedScope($event->getRequest());
    }

    public function onEmailConfirmed(GetResponseUserEvent $event)
    {
        /** @var PersonInterface $person */
        $person = $event->getUser();
        if (!($person instanceof PersonInterface)) {
            return;
        }

        $person->setEmailConfirmedAt(new \DateTime());
        $person->setEmailExpiration(null);

        $this->session->getFlashBag()->get('alert.unconfirmed.email');

        $url = $this->router->generate('lc_email_confirmed');
        $event->setResponse(new RedirectResponse($url));
    }

    public function setEntityManager(EntityManager $var)
    {
        $this->em = $var;
    }
}
