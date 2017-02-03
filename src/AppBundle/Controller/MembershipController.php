<?php

namespace AppBundle\Controller;

use AppBundle\Entity\Adherent;
use AppBundle\Entity\AdherentActivationToken;
use AppBundle\Exception\AdherentAlreadyEnabledException;
use AppBundle\Exception\AdherentTokenExpiredException;
use AppBundle\Form\AdherentInterestsFormType;
use AppBundle\Form\DonationRequestType;
use AppBundle\Form\MembershipChooseNearbyCommitteeType;
use AppBundle\Form\MembershipRequestType;
use AppBundle\Intl\UnitedNationsBundle;
use AppBundle\Membership\MembershipRequest;
use AppBundle\Membership\OnBoarding\RegisteringAdherent;
use AppBundle\Membership\OnBoarding\RegisteringDonation;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Entity;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MembershipController extends Controller
{
    /**
     * This action enables a guest user to adhere to the community.
     *
     * @Route("/inscription", name="app_membership_register")
     * @Method("GET|POST")
     */
    public function registerAction(Request $request): Response
    {
        $membership = MembershipRequest::createWithCaptcha($request->request->get('g-recaptcha-response'));
        $form = $this->createForm(MembershipRequestType::class, $membership)
            ->add('submit', SubmitType::class, ['label' => 'J\'adhère'])
        ;

        if ($form->handleRequest($request)->isSubmitted() && $form->isValid()) {
            $adherent = $this->get('app.membership_request_handler')->handle($membership);

            $this->get('app.membership.on_boarding_util')->beginOnBoardingProcess($adherent);

            return $this->redirectToRoute('app_membership_donate');
        }

        return $this->render('membership/register.html.twig', [
            'membership' => $membership,
            'form' => $form->createView(),
            'countries' => UnitedNationsBundle::getCountries($request->getLocale()),
        ]);
    }

    /**
     * This action enables a new user to donate as a second step of the
     * registration process, thus he/she may not be logged-in/activated yet.
     *
     * @Route("/inscription/don", name="app_membership_donate")
     * @Method("GET|POST")
     */
    public function donateAction(Request $request, RegisteringDonation $donation): Response
    {
        $donationRequest = $donation->getDonationRequest();
        $form = $this->createForm(DonationRequestType::class, $donationRequest, [
            'locale' => $request->getLocale(),
            'submit_label' => 'adherent.submit_donation_label',
            'sponsor_form' => false,
        ])
            // Because here the user is still anonymous
            // it allows to go to step three, see next action pinInterestsAction()
            ->add('pass', SubmitType::class)
        ;

        if ($form->handleRequest($request)->isSubmitted()) {
            if ($form->get('pass')->isClicked()) {
                // Ignore this step
                return $this->redirectToRoute('app_membership_pin_interests');
            }

            if ($form->isValid()) {
                $donation = $this->get('app.donation_request.handler')->handle($donationRequest, $request->getClientIp());

                return $this->redirectToRoute('donation_pay', [
                    'uuid' => $donation->getUuid()->toString(),
                ]);
            }
        }

        return $this->render('membership/donate.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * This action enables a new user to pin his/here interests as a third step
     * of the registration process, thus he/she is not logged-in/activated yet.
     *
     * @Route("/inscription/centre-interets", name="app_membership_pin_interests")
     * @Method("GET|POST")
     */
    public function pinInterestsAction(Request $request, RegisteringAdherent $registering): Response
    {
        $form = $this->createForm(AdherentInterestsFormType::class, $registering->getAdherent())
            ->add('pass', SubmitType::class)
            ->add('submit', SubmitType::class)
        ;

        if ($form->handleRequest($request)->isSubmitted()) {
            if ($form->get('submit')->isClicked() && $form->isValid()) {
                $this->getDoctrine()->getManager()->flush();
            }

            return $this->redirectToRoute('app_membership_choose_nearby_committee');
        }

        return $this->render('membership/pin_interests.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * This action enables a new user to follow some committees.
     *
     * @Route("/inscription/choisir-des-comites", name="app_membership_choose_nearby_committee")
     * @Method("GET|POST")
     */
    public function chooseNearbyCommitteeAction(Request $request, RegisteringAdherent $registering): Response
    {
        $adherent = $registering->getAdherent();
        $form = $this->createForm(MembershipChooseNearbyCommitteeType::class, null, ['adherent' => $adherent])
            ->add('submit', SubmitType::class, ['label' => 'Terminer'])
            ->handleRequest($request)
        ;

        if ($form->isSubmitted() && $form->isValid()) {
            $this->get('app.committee_manager')->followCommittees($adherent, $form->get('committees')->getData());

            $this->get('app.membership.on_boarding_util')->endOnBoardingProcess();

            $this->addFlash('info', $this->get('translator')->trans('adherent.registration.success'));

            return $this->redirectToRoute('homepage');
        }

        return $this->render('membership/choose_nearby_committee.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * This action enables a new user to activate his\her newly created
     * membership account.
     *
     * @Route(
     *   path="/inscription/finaliser/{adherent_uuid}/{activation_token}",
     *   name="app_membership_activate",
     *   requirements={
     *     "adherent_uuid": "%pattern_uuid%",
     *     "activation_token": "%pattern_sha1%"
     *   }
     * )
     * @Method("GET")
     * @Entity("adherent", expr="repository.findOneByUuid(adherent_uuid)")
     * @Entity("activationToken", expr="repository.findByToken(activation_token)")
     */
    public function activateAction(Adherent $adherent, AdherentActivationToken $activationToken): Response
    {
        try {
            $this->get('app.adherent_account_activation_handler')->handle($adherent, $activationToken);
            $this->addFlash('info', $this->get('translator')->trans('adherent.activation.success'));
        } catch (AdherentAlreadyEnabledException $e) {
            $this->addFlash('info', $this->get('translator')->trans('adherent.activation.already_active'));
        } catch (AdherentTokenExpiredException $e) {
            $this->addFlash('info', $this->get('translator')->trans('adherent.activation.expired_key'));
        }

        // Other exceptions that may be raised will be caught by Symfony.

        return $this->redirectToRoute('app_adherent_login');
    }
}
