<?php

namespace Dotdigitalgroup\Email\Controller\Report;

class Bestsellers extends \Dotdigitalgroup\Email\Controller\Response
{
    public function execute()
    {
        //authenticate
        if ($this->authenticate()) {
            $this->_view->loadLayout();
            $this->_view->renderLayout();
        }
    }
}
