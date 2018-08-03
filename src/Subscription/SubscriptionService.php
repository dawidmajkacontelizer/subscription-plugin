<?php


namespace Acme\SyliusExamplePlugin\Subscription;


use Acme\SyliusExamplePlugin\Entity\Order;
use Acme\SyliusExamplePlugin\Entity\Subscription;
use Acme\SyliusExamplePlugin\Entity\SubscriptionStates;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Bundle\OrderBundle\NumberAssigner\OrderNumberAssignerInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Core\Repository\CustomerRepositoryInterface;
use Sylius\Component\Core\TokenAssigner\UniqueIdBasedOrderTokenAssigner;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\OrderTransitions;
use Sylius\Component\Order\Processor\CompositeOrderProcessor;
use Sylius\Component\Payment\Factory\PaymentFactoryInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class SubscriptionService
{
    private $localeContext;
    private $channelContext;
    private $customerRepository;
    private $tokenStorage;
    private $entityManager;
    private $itemQuantityModifier;
    private $compositeOrderProcessor;
    private $numberAssigner;
    private $tokenAssigner;
    private $paymentFactory;

    public function __construct(
        LocaleContextInterface $localeContext
        , ChannelContextInterface $channelContext
        , CustomerRepositoryInterface $customerRepository
        , TokenStorageInterface $tokenStorage
        , EntityManagerInterface $entityManager
        , OrderItemQuantityModifierInterface $itemQuantityModifier
        , CompositeOrderProcessor $compositeOrderProcessor
        , OrderNumberAssignerInterface $numberAssigner
        , UniqueIdBasedOrderTokenAssigner $tokenAssigner
        , PaymentFactoryInterface $paymentFactory
    )
    {
        $this->localeContext = $localeContext;
        $this->channelContext = $channelContext;
        $this->customerRepository = $customerRepository;
        $this->tokenStorage = $tokenStorage;
        $this->entityManager = $entityManager;
        $this->itemQuantityModifier = $itemQuantityModifier;
        $this->compositeOrderProcessor = $compositeOrderProcessor;
        $this->numberAssigner = $numberAssigner;
        $this->tokenAssigner = $tokenAssigner;
        $this->paymentFactory = $paymentFactory;

    }

    /**
     * @param Order $order
     * @return bool
     */
    public function createSubscriptionAndAddOrder(Order $order)
    {
        try {
            //more than one item or product is not subscribable
            if ($order->countItems() != 1 || !$order->getItems()[0]->getProduct()->isSubscribable()) {
                //Sylius sometimes uses previously created order, make sure to nullify subscription if so
                if ($order->isSubscriptionType()) {
                    $order->setSubscription(null);
                    $this->entityManager->persist($order);
                    $this->entityManager->flush();
                }
                return false;
            }
            //order already has subscription
            if ($order->isSubscriptionType()) {
                return false;
            }
        } catch (\Exception $exception) {
            //log it ?
            return false;
        }
        /** @var string $localeCode */
        $localeCode = $this->localeContext->getLocaleCode();
        /** @var ChannelInterface $channel */
        $channel = $this->channelContext->getChannel();
        /** @var CustomerInterface|null $customer */
        $customer = $order->getCustomer();

        $subscription = new Subscription();
        $subscription->setCustomer($customer);
        $subscription->setChannel($channel);
        $subscription->addOrder($order);
        $subscription->setLocaleCode($localeCode);

        /** @var OrderItemInterface $item */
        $orderItem = $order->getItems()[0];
        $quantity = $orderItem->getQuantity();
        $subscription->setCycles($quantity);
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();
        return true;
    }

    public function splitSubscriptionOrders(Order $order)
    {
        //Check if subscription order
        $subscription = $order->getSubscription();
        if ($order->isSubscriptionType()) {
            return false;
        }

        if ($order->countItems() != 1) {
            //more than one item? shouldn't happen!
            throw new BadRequestHttpException('Something is not right :(');
        }

        //Get item quantity
        /** @var OrderItemInterface $item */
        $orderItem = $order->getItems()[0];
        $quantity = $orderItem->getQuantity();

        if($quantity != $subscription->getCycles()){
            //somebody changed quantity during checkout process
            $subscription->setCycles($quantity);
        }



        /** @var PaymentInterface $payment */
        $basePayment = $order->getPayments()->first();
        /** @var PaymentMethodInterface $paymentMethod */
        $basePaymentMethod = $basePayment->getMethod();
        /** @var string $currencyCode */
        $baseCurrencyCode = $basePayment->getCurrencyCode();
        /** @var AddressInterface $baseShippingAddress */
        $baseShippingAddress = $order->getShippingAddress();
        /** @var AddressInterface $baseBillingAddress */
        $baseBillingAddress = $order->getBillingAddress();


        $this->itemQuantityModifier->modify($orderItem, 1);
        $order->getPayments()->clear();
        /** @var PaymentInterface $payment */
        $payment = $this->paymentFactory->createNew();
        $payment->setMethod($basePaymentMethod);
        $payment->setCurrencyCode($baseCurrencyCode);
        $order->addPayment($payment);
        $order->setValidFrom(new \DateTime());
        $this->compositeOrderProcessor->process($order);
        $payment->setState('new');
        $this->entityManager->remove($basePayment);
        $this->entityManager->persist($payment);
        $this->entityManager->persist($order);
        $this->entityManager->flush();
        $date = new \DateTime();
        $date->modify('midnight first day of next month')->modify(sprintf('+%d days', $subscription->getDayOfTheMonth() - 1));
        //Duplicate orders
        for ($i = 1; $i < $quantity; ++$i) {
            $newOrder = clone $order;
            $newOrder->setNumber(null);
            $this->numberAssigner->assignNumber($newOrder);
//            $newOrder->setTokenValue(null);
            $this->tokenAssigner->assignTokenValue($newOrder);
            /** @var PaymentInterface $payment */
            $payment = $this->paymentFactory->createNew();
            $payment->setMethod($basePaymentMethod);
            $payment->setCurrencyCode($baseCurrencyCode);
            $newOrder->addPayment($payment);
            $newOrder->setShippingAddress(clone $baseShippingAddress);
            $newOrder->setBillingAddress(clone $baseBillingAddress);
            $newOrder->setValidFrom(clone $date);
            $this->compositeOrderProcessor->process($newOrder);
            $payment->setState('new');
            $this->entityManager->persist($payment);
            $this->entityManager->persist($newOrder);
            $date->modify("+1 month");
        }
        $subscription->setState(SubscriptionStates::STATE_IN_PROGRESS);
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();
    }

    /**
     * @param Subscription $subscription
     * @return int - liczba anulowanych zamówień
     * @author Damian Frańczuk <damian.franczuk@contelizer.pl>
     */
    public function cancelSubscription(Subscription $subscription){
        $orders = $subscription->getOrders();
        $counter = 0;
        foreach ($orders as $order){
            $now = new \DateTime();
            $validFrom = $order->getValidFrom();
            $paymentState = $order->getPaymentState();
            if($validFrom > $now && $paymentState === OrderPaymentStates::STATE_AWAITING_PAYMENT){
                $bluemediaService = $this->container->get('app.services.bluemedia');
                $bluemediaService->deactivateRecurring($order);
                $stateMachineFactory = $this->container->get('sm.factory');
                $stateMachineOrder = $stateMachineFactory->get($order, OrderTransitions::GRAPH);
                $stateMachineOrder->apply(OrderTransitions::TRANSITION_CANCEL);
                $this->container->get('sylius.manager.order')->flush();
                $counter++;
            }
        }
        $subscription->setState(SubscriptionStates::STATE_CANCELLED);
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();
        return $counter;
    }

}