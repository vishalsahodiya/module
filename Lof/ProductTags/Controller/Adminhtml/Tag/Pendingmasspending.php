<?php

namespace Lof\ProductTags\Controller\Adminhtml\Tag;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Backend\App\Action\Context;
use Magento\Ui\Component\MassAction\Filter;
use Lof\ProductTags\Model\ResourceModel\Relatedtag\CollectionFactory;

class Pendingmasspending extends \Magento\Backend\App\Action {

    protected $filter;
    protected $collectionFactory;
    public function __construct(Context $context, Filter $filter, CollectionFactory $collectionFactory)
    {
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        parent::__construct($context);
    }
     
    public function execute() {
        
        $itemIds = $this->getRequest()->getPost('id');
        foreach ($itemIds as $itemId) {
           $model = $this->_objectManager->create('Lof\ProductTags\Model\Tag');
            $model->load($itemId);
            $model->setStatus(1);
            $model->save();

            $tag_title = $model->getTagTitle();
            
            $post = $this->_objectManager->create('Lof\ProductTags\Model\Relatedtag')->load($tag_title,"relatedtag");
            $customer_id = $post->getCustomerId();
            if($model->getCustomerId() == $customer_id){
                $post->setIsApprove(1);
                $post->save();
            }

        }

        $this->messageManager->addSuccessMessage(__('A total of %1 tag(s) have been pending.', count($itemIds)));

        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setUrl($this->_redirect->getRefererUrl());
        return $resultRedirect;

    }
}
