<?php

namespace Dotdigitalgroup\Email\Observer\Customer;

/**
 * New review automation.
 */
class ReviewSaveAutomation implements \Magento\Framework\Event\ObserverInterface
{
    /**
     * @var \Dotdigitalgroup\Email\Helper\Data
     */
    private $helper;
    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;
    /**
     * @var \Magento\Customer\Model\CustomerFactory
     */
    private $customerFactory;
    /**
     * @var \Dotdigitalgroup\Email\Model\ReviewFactory
     */
    private $reviewFactory;

    /**
     * @var \Dotdigitalgroup\Email\Model\AutomationFactory
     */
    private $automationFactory;

    /**
     * ReviewSaveAutomation constructor.
     *
     * @param \Dotdigitalgroup\Email\Model\ReviewFactory     $reviewFactory
     * @param \Dotdigitalgroup\Email\Model\AutomationFactory $automationFactory
     * @param \Magento\Customer\Model\CustomerFactory        $customerFactory
     * @param \Dotdigitalgroup\Email\Helper\Data             $data
     * @param \Magento\Store\Model\StoreManagerInterface     $storeManagerInterface
     */
    public function __construct(
        \Dotdigitalgroup\Email\Model\ReviewFactory $reviewFactory,
        \Dotdigitalgroup\Email\Model\AutomationFactory $automationFactory,
        \Magento\Customer\Model\CustomerFactory $customerFactory,
        \Dotdigitalgroup\Email\Helper\Data $data,
        \Magento\Store\Model\StoreManagerInterface $storeManagerInterface
    ) {
        $this->reviewFactory     = $reviewFactory;
        $this->automationFactory = $automationFactory;
        $this->customerFactory   = $customerFactory;
        $this->helper            = $data;
        $this->storeManager      = $storeManagerInterface;
    }

    /**
     * If it's configured to capture on shipment - do this.
     *
     * @param \Magento\Framework\Event\Observer $observer
     *
     * @return $this
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $dataObject = $observer->getEvent()->getDataObject();

        if ($dataObject->getCustomerId()
            && $dataObject->getStatusId()
            == \Magento\Review\Model\Review::STATUS_APPROVED
        ) {
            $customerId = $dataObject->getCustomerId();
            $this->helper->setConnectorContactToReImport($customerId);
            //save review info in the table
            $this->registerReview($dataObject);
            $store = $this->storeManager->getStore($dataObject->getStoreId());
            $storeName = $store->getName();
            $website = $this->storeManager->getStore($store)->getWebsite();
            $customer = $this->customerFactory->create();
            $customer->getResource()->load($customer, $customerId);
            //if api is not enabled
            if (!$this->helper->isEnabled($website)) {
                return $this;
            }

            $programId
                = $this->helper->getWebsiteConfig('connector_automation/visitor_automation/review_automation');
            if ($programId) {
                $automation = $this->automationFactory->create();
                $automation->setEmail($customer->getEmail())
                    ->setAutomationType(\Dotdigitalgroup\Email\Model\Sync\Automation::AUTOMATION_TYPE_NEW_REVIEW)
                    ->setEnrolmentStatus(\Dotdigitalgroup\Email\Model\Sync\Automation::AUTOMATION_STATUS_PENDING)
                    ->setTypeId($dataObject->getReviewId())
                    ->setWebsiteId($website->getId())
                    ->setStoreName($storeName)
                    ->setProgramId($programId);
                $automation->getResource()->save($automation);
            }
        }

        return $this;
    }

    /**
     * Register review.
     *
     * @param $review
     */
    private function registerReview($review)
    {
        try {
            $reviewModel = $this->reviewFactory->create();
            $reviewModel->setReviewId($review->getReviewId())
                ->setCustomerId($review->getCustomerId())
                ->setStoreId($review->getStoreId());
            $reviewModel->getResource()->save($reviewModel);
        } catch (\Exception $e) {
            $this->helper->debug((string)$e, []);
        }
    }
}
