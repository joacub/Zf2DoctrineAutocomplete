<?php
/**
 * Created by PhpStorm.
 * User: johanrodriguezramos
 * Date: 21/5/16
 * Time: 20:01
 */

namespace Zf2DoctrineAutocomplete\Controller;


class AbstractActionController extends \Zend\Mvc\Controller\AbstractActionController
{

    public function getServiceLocator()
    {
        return $this->getEvent()->getApplication()->getServiceManager();
    }

}