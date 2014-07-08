<?php

namespace PROCERGS\LoginCidadao\CoreBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use JMS\Serializer\SerializationContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use PROCERGS\LoginCidadao\CoreBundle\Helper\NfgHelper;
use FOS\UserBundle\FOSUserEvents;
use FOS\UserBundle\Event\FilterUserResponseEvent;
use FOS\UserBundle\Util\TokenGenerator;
use PROCERGS\LoginCidadao\CoreBundle\Form\Type\DocFormType;
use FOS\UserBundle\Event\GetResponseUserEvent;
use FOS\UserBundle\Event\FormEvent;
use PROCERGS\LoginCidadao\CoreBundle\EventListener\ProfileEditListner;
use PROCERGS\LoginCidadao\CoreBundle\Form\Type\DocRgFormType;
use PROCERGS\LoginCidadao\CoreBundle\Entity\Rg;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\FormError;

class PersonController extends Controller
{

    public function connectFacebookWithAccountAction()
    {
        $fbService = $this->get('fos_facebook.user.login');
        //todo: check if service is successfully connected.
        $fbService->connectExistingAccount();
        return $this->redirect($this->generateUrl('fos_user_profile_edit'));
    }

    public function loginFbAction()
    {
        return $this->redirect($this->generateUrl("_homepage"));
    }

    /**
     * @Route("/person/authorization/{clientId}/revoke", name="lc_revoke")
     * @Template()
     */
    public function revokeAuthorizationAction($clientId)
    {
        $form = $this->createForm('procergs_revoke_authorization');
        $form->handleRequest($this->getRequest());

        if ($form->isValid()) {
            $security = $this->get('security.context');
            $em = $this->getDoctrine()->getManager();
            $tokens = $em->getRepository('PROCERGSOAuthBundle:AccessToken');
            $clients = $em->getRepository('PROCERGSOAuthBundle:Client');
            $translator = $this->get('translator');

            try {

                if (false === $security->isGranted('ROLE_USER')) {
                    throw new AccessDeniedException();
                }

                $user = $security->getToken()->getUser();

                $client = $clients->find($clientId);
                $accessTokens = $tokens->findBy(array(
                    'client' => $client,
                    'user' => $user
                ));
                $refreshTokens = $em->getRepository('PROCERGSOAuthBundle:RefreshToken')
                        ->findBy(array(
                    'client' => $client,
                    'user' => $user
                ));
                $authorizations = $user->getAuthorizations();
                $success = false;

                foreach ($authorizations as $auth) {
                    if ($auth->getPerson()->getId() == $user->getId() && $auth->getClient()->getId() == $clientId) {

                        foreach ($accessTokens as $accessToken) {
                            $em->remove($accessToken);
                        }

                        foreach ($refreshTokens as $refreshToken) {
                            $em->remove($refreshToken);
                        }

                        $em->remove($auth);
                        $em->flush();

                        $this->get('session')->getFlashBag()->add('success',
                                $translator->trans('Authorization successfully revoked.'));
                        $success = true;
                    }
                }

                if (!$success) {
                    throw new \InvalidArgumentException($translator->trans("Authorization not found."));
                }
            } catch (AccessDeniedException $e) {
                $this->get('session')->getFlashBag()->add('error',
                        $translator->trans("Access Denied."));
            } catch (\Exception $e) {
                $this->get('session')->getFlashBag()->add('error',
                        $translator->trans("Wasn't possible to disable this service."));
                $this->get('session')->getFlashBag()->add('error',
                        $e->getMessage());
            }
        } else {
            $this->get('session')->getFlashBag()->add('error',
                    $translator->trans("Wasn't possible to disable this service."));
        }

        return $this->redirect($this->generateUrl('lc_app_details',
                                array('clientId' => $clientId)));
    }

    /**
     * @Route("/person/checkEmailAvailable", name="lc_email_available")
     */
    public function checkEmailAvailableAction(Request $request)
    {
        $translator = $this->get('translator');
        $email = $request->get('email');

        $person = $this->getDoctrine()
                ->getRepository('PROCERGSLoginCidadaoCoreBundle:Person')
                ->findByEmail($email);

        $data = array('valid' => true);
        if (count($person) > 0) {
            $data = array(
                'valid' => false,
                'message' => $translator->trans('The email is already used')
            );
        }

        $response = new JsonResponse();
        $response->setData($data);

        return $response;
    }

    /**
     * @Route("/profile/change-username", name="lc_update_username")
     * @Template()
     */
    public function updateUsernameAction()
    {
        $user = $this->getUser();
        $userManager = $this->container->get('fos_user.user_manager');

        $formBuilder = $this->createFormBuilder($user)
                ->add('username', 'text')
                ->add('save', 'submit');

        $emptyPassword = strlen($user->getPassword()) == 0;
        if ($emptyPassword) {
            $formBuilder->add('plainPassword', 'repeated',
                    array(
                'type' => 'password'
            ));
        } else {
            $formBuilder->add('current_password', 'password',
                    array(
                'required' => true,
                'constraints' => new UserPassword(),
                'mapped' => false
            ));
        }

        $form = $formBuilder->getForm();

        $form->handleRequest($this->getRequest());
        if ($form->isValid()) {
            $data = $form->getData();
            $hasChangedPassword = $data->getPassword() == '';
            $user->setUsername($data->getUsername());

            $userManager->updateUser($user);

            $translator = $this->get('translator');
            $this->get('session')->getFlashBag()->add('success',
                    $translator->trans('Updated username successfully!'));

            $response = $this->redirect($this->generateUrl('lc_update_username'));
            if ($hasChangedPassword) {
                $request = $this->getRequest();
                $dispatcher = $this->container->get('event_dispatcher');
                $dispatcher->dispatch(FOSUserEvents::CHANGE_PASSWORD_COMPLETED,
                        new FilterUserResponseEvent($user, $request, $response));
            }
            return $response;
        }

        return array('form' => $form->createView(), 'emptyPassword' => $emptyPassword);
    }

    /**
     * @Route("/cpf/register", name="lc_registration_cpf")
     * @Template("PROCERGSLoginCidadaoCoreBundle:Person:registration/cpf.html.twig")
     */
    public function registrationCpfAction(Request $request)
    {
        $person = $this->getUser();
        if (is_numeric($cpf = preg_replace('/[^0-9]/', '', $request->get('cpf'))) && strlen($cpf) == 11) {
            $person->setCpf($cpf);
        }
        $formBuilder = $this->createFormBuilder($person);
        if (!$person->getCpf()) {
            $formBuilder->add('cpf', 'text', array('required' => true));
        }
        $form = $formBuilder->getForm();
        $form->handleRequest($this->getRequest());
        $messages = '';
        if ($form->isValid()) {
            $person->setCpfExpiration(null);
            $this->container->get('fos_user.user_manager')->updateUser($person);
            return $this->redirect($this->generateUrl('lc_home'));
        }
        return array(
            'form' => $form->createView(), 'messages' => $messages, 'isExpired' => $person->isCpfExpired()
        );
    }

    /**
     * @Route("/facebook/unlink", name="lc_unlink_facebook")
     */
    public function unlinkFacebookAction()
    {
        $person = $this->getUser();
        $translator = $this->get('translator');
        if ($person->hasPassword()) {
            $person->setFacebookId(null)
                    ->setFacebookUsername(null);
            $userManager = $this->get('fos_user.user_manager');
            $userManager->updateUser($person);

            $this->get('session')->getFlashBag()->add('success',
                    $translator->trans("social-networks.unlink.facebook.success"));
        } else {
            $this->get('session')->getFlashBag()->add('error',
                    $translator->trans("social-networks.unlink.no-password"));
        }

        return $this->redirect($this->generateUrl('fos_user_profile_edit'));
    }

    /**
     * @Route("/twitter/unlink", name="lc_unlink_twitter")
     */
    public function unlinkTwitterAction()
    {
        $person = $this->getUser();
        $translator = $this->get('translator');
        if ($person->hasPassword()) {
            $person->setTwitterId(null)
                    ->setTwitterUsername(null)
                    ->setTwitterAccessToken(null);
            $userManager = $this->get('fos_user.user_manager');
            $userManager->updateUser($person);

            $this->get('session')->getFlashBag()->add('success',
                    $translator->trans("social-networks.unlink.twitter.success"));
        } else {
            $this->get('session')->getFlashBag()->add('error',
                    $translator->trans("social-networks.unlink.no-password"));
        }

        return $this->redirect($this->generateUrl('fos_user_profile_edit'));
    }

    /**
     * @Route("/google/unlink", name="lc_unlink_google")
     */
    public function unlinkGoogleAction()
    {
        $person = $this->getUser();
        $translator = $this->get('translator');
        if ($person->hasPassword()) {
            $person->setGoogleId(null)
                    ->setGoogleUsername(null)
                    ->setGoogleAccessToken(null);
            $userManager = $this->get('fos_user.user_manager');
            $userManager->updateUser($person);

            $this->get('session')->getFlashBag()->add('success',
                    $translator->trans("social-networks.unlink.google.success"));
        } else {
            $this->get('session')->getFlashBag()->add('error',
                    $translator->trans("social-networks.unlink.no-password"));
        }

        return $this->redirect($this->generateUrl('fos_user_profile_edit'));
    }

    /**
     * @Route("/email/resend-confirmation", name="lc_resend_confirmation_email")
     */
    public function resendConfirmationEmail()
    {
        $mailer = $this->get('fos_user.mailer');
        $translator = $this->get('translator');
        $person = $this->getUser();

        if (is_null($person->getEmailConfirmedAt())) {
            if (is_null($person->getConfirmationToken())) {
                $tokenGenerator = new TokenGenerator();
                $person->setConfirmationToken($tokenGenerator->generateToken());
                $userManager = $this->get('fos_user.user_manager');
                $userManager->updateUser($person);
            }
            $mailer->sendConfirmationEmailMessage($person);
            $this->get('session')->getFlashBag()->add('success',
                    $translator->trans("email-confirmation.resent"));
        }

        return $this->redirect($this->generateUrl('fos_user_profile_edit'));
    }

    /**
     * @Route("/profile/doc/edit", name="lc_profile_doc_edit")
     * @Template()
     */
    public function docEditAction(Request $request)
    {
        $user = $this->getUser();

        $dispatcher = $this->container->get('event_dispatcher');

        $event = new GetResponseUserEvent($user, $request);
        $dispatcher->dispatch(FOSUserEvents::PROFILE_EDIT_INITIALIZE, $event);

        $form = $this->createForm(new DocFormType(), $user);
        $form->handleRequest($this->getRequest());
        if ($form->isValid()) {

            $event = new FormEvent($form, $request);
            $dispatcher->dispatch(ProfileEditListner::PROFILE_DOC_EDIT_SUCCESS,
                    $event);

            $userManager = $this->get('fos_user.user_manager');
            $userManager->updateUser($user);
            $translator = $this->get('translator');
            $this->get('session')->getFlashBag()->add('success',
                    $translator->trans("Documents were successfully changed"));
        }
        return array('form' => $form->createView());
    }
    
    /**
     * @Route("/profile/doc/rg/edit", name="lc_profile_doc_rg_edit")
     * @Template()
     */
    public function docRgEditAction(Request $request)
    {
        $form = $this->createForm(new DocRgFormType());
        $rg = null;
        if (($id = $request->get('id')) || (($data = $request->get($form->getName())) && ($id = $data['id']))) {
            $rg = $this->getDoctrine()
            ->getManager ()
            ->getRepository('PROCERGSLoginCidadaoCoreBundle:Rg')->findOneBy(array('person' => $this->getUser(), 'id' => $id));
        }
        if (!$rg) {
            $rg = new Rg();
            $rg->setPerson($this->getUser());
        }
        $form = $this->createForm(new DocRgFormType(), $rg);
        $form->handleRequest($this->getRequest());
        if ($form->isValid()) {
            $manager = $this->getDoctrine()->getManager();
            $dql = $manager->getRepository('PROCERGSLoginCidadaoCoreBundle:Rg')
            ->createQueryBuilder('u')
            ->where('u.person = :person and u.uf = :uf')
            ->setParameter('person',$this->getUser())
            ->setParameter('uf', $form->get('uf')->getData())
            ->orderBy('u.id', 'ASC');            
            if ($rg->getId()) {
                $dql->andWhere('u != :rg')->setParameter('rg', $rg);
            }
            $has = $dql->getQuery()->getResult();
            if ($has) {
                $form->get('uf')->addError(new FormError($this->get('translator')->trans('there is a RG already registered for this UF')));
                return array('form' => $form->createView());
            }
            $manager->persist($rg);
            $manager->flush();
            $resp = new Response('<script>rgGrid.getGrid();$(\'#edit-rg\').modal(\'hide\');</script>');
            return $resp; 
        }
        return array('form' => $form->createView());
    }
    
    /**
     * @Route("/profile/doc/rg/list", name="lc_profile_doc_rg_list")
     * @Template()
     */
    public function docRgListAction(Request $request)
    {
        $resultset = $this->getDoctrine()
            ->getManager ()
            ->getRepository('PROCERGSLoginCidadaoCoreBundle:Rg')
            ->createQueryBuilder('u')
            ->select('u.id, u.val, b.iso6')
            ->join('PROCERGSLoginCidadaoCoreBundle:Uf', 'b', 'with', 'u.uf = b')
            ->where('u.person = :person')
            ->setParameters(array('person' => $this->getUser()))
            ->orderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();
        return array('resultset' => $resultset);
    }

    /**
     * @Route("/register/prefilled", name="lc_prefilled_registration")
     */
    public function preFilledRegistrationAction(Request $request)
    {
        /** @var $formFactory \FOS\UserBundle\Form\Factory\FactoryInterface */
        $formFactory = $this->container->get('fos_user.registration.form.factory');
        /** @var $userManager \FOS\UserBundle\Model\UserManagerInterface */
        $userManager = $this->container->get('fos_user.user_manager');
        /** @var $dispatcher \Symfony\Component\EventDispatcher\EventDispatcherInterface */
        $dispatcher = $this->container->get('event_dispatcher');

        $user = $userManager->createUser();
        $user->setEnabled(true);

        $fullName = $request->get('full_name');

        if (!is_null($fullName)) {
            $name = explode(' ', trim($fullName), 2);
            $user->setFirstName($name[0]);
            $user->setSurname($name[1]);
        }
        $user->setEmail($request->get('email'));
        $user->setMobile($request->get('mobile'));

        $event = new GetResponseUserEvent($user, $request);
        $dispatcher->dispatch(FOSUserEvents::REGISTRATION_INITIALIZE, $event);

        if (null !== $event->getResponse()) {
            return $event->getResponse();
        }

        $form = $formFactory->createForm();

        $form->add('firstName', 'text',
                        array('required' => false, 'label' => 'form.firstName', 'translation_domain' => 'FOSUserBundle'))
                ->add('surname', 'text',
                        array('required' => false, 'label' => 'form.surname', 'translation_domain' => 'FOSUserBundle'));

        $form->setData($user);

        if ('POST' === $request->getMethod()) {
            $form->bind($request);

            if ($form->isValid()) {
                $event = new FormEvent($form, $request);
                $dispatcher->dispatch(FOSUserEvents::REGISTRATION_SUCCESS,
                        $event);

                $userManager->updateUser($user);

                if (null === $response = $event->getResponse()) {
                    $url = $this->container->get('router')->generate('fos_user_registration_confirmed');
                    $response = new RedirectResponse($url);
                }

                $dispatcher->dispatch(FOSUserEvents::REGISTRATION_COMPLETED,
                        new FilterUserResponseEvent($user, $request, $response));

                return $response;
            }
        }

        return $this->container->get('templating')->renderResponse('PROCERGSLoginCidadaoCoreBundle:Person:registration/preFilledRegistration.html.twig',
                        array(
                    'form' => $form->createView(),
        ));
    }

}
