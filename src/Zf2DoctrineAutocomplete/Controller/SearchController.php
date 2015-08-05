<?php

/**
 * @author fabio <paiva.fabiofelipe@gmail.com>
 */

namespace Zf2DoctrineAutocomplete\Controller;

use Doctrine\ORM\EntityManager;
use Nette\Diagnostics\Debugger;
use Zend\Form\Element\Collection;
use Zend\Form\InputFilterProviderFieldset;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;
use Zend\Form\Factory;

class SearchController extends AbstractActionController {

    private $proxy;
    private $objects;
    private $om;
    private $options;

    public function searchAction() {
        $elementName = $this->params()->fromRoute('element');
        $elementName = str_replace('-', '\\', $elementName);

        $form = $this->params()->fromQuery('form');
        $form = str_replace('-', '\\', $form);

        Debugger::$productionMode = false;
        Debugger::dump($_GET);
        exit;

        if($form) {
            $form = $this->getServiceLocator()
                ->get('FormElementManager')
                ->get($form);

            $elementName = $this->params()->fromQuery('name');

            if($form->has($elementName)) {
                $element = $form->get($elementName);
            } else {
                $elementNameParts = explode('[', $elementName);
                $elementNameSimple = current($elementNameParts);
                $element = $form->get($elementNameSimple);
            }

        } else {
            $factory = new Factory();
            $element = $factory->createElement(array(
                'type' => $elementName,
                'options' => array(
                    'sm' => $this->getServiceLocator()
                )
            ));
        }


        $term = mb_strtolower($this->params()->fromQuery('term', ''));

        if($element instanceof Collection) {
            $elementName = str_replace(']', '', $elementNameParts[2]);
            $targetElementFieldset = $element->getTargetElement();
            /**
             * @var $targetElementFieldset InputFilterProviderFieldset
             */
            $element = $targetElementFieldset->get($elementName);
        }

        $options = $element->getOptions();

        Debugger::$productionMode = false;
        Debugger::dump($options);
        exit;

        $this->setOm($options['object_manager']);
        $proxy = $element->getProxy();

        if($proxy->getFindMethod()) {
            $options['find_method'] = $proxy->getFindMethod();
        }

        $this->setProxy($proxy);
        $targetClass = $proxy->getTargetClass();

        $this->setOptions($options);

        $qb = $this->getOm()->getRepository($targetClass)
            ->createQueryBuilder('q');
        $driver = '';
        if (class_exists("\Doctrine\ORM\QueryBuilder") && $qb instanceof \Doctrine\ORM\QueryBuilder) {
            /* @var $qb \Doctrine\ORM\QueryBuilder */
            $qb->setMaxResults(20);
            $driver = 'orm';
        } elseif (class_exists("\Doctrine\ODM\MongoDB\Query\Builder") && $qb instanceof \Doctrine\ODM\MongoDB\Query\Builder) {
            /* @var $qb \Doctrine\ODM\MongoDB\Query\Builder */
            $qb->limit(20);
            $driver = 'odm';
        } else {
            throw new \Exception('Can\'t find ORM or ODM doctrine driver');
        }

        foreach ($options['searchFields'] as $field) {
            if ($driver == 'orm') {
                $qb->orWhere($qb->expr()->like('q.' . $field, $qb->expr()->literal("%{$term}%")));
            } elseif ($driver == 'odm') {
                $qb->addOr($qb->expr()->field($field)->equals(new \MongoRegex("/{$term}/i")));
            }
        }
        if ($options['orderBy']) {
            if ($driver == 'orm') {
                $qb->orderBy('q.' . $options['orderBy'][0], $options['orderBy'][1]);
            } elseif ($driver == 'odm') {
                $qb->sort($options['orderBy'][0], $options['orderBy'][1]);
            }
        }

        if (isset($options['find_method']) && $options['find_method']) {
            if ($driver == 'orm') {
                $findMethod = $options['find_method'];

                if (!isset($findMethod['name'])) {
                    throw new \RuntimeException('No method name was set');
                }
                $findMethodName   = $findMethod['name'];
                $findMethodParams = isset($findMethod['params']) ? array_change_key_case($findMethod['params']) : array();

                $iParam = 0;
                foreach($findMethodParams['criteria'] as $name => $value) {
                    $iParam++;
                    $qb->andWhere('q.' . $name . '=' . $value);
                }

            } elseif ($driver == 'odm') {
//                 $qb->sort($options['orderBy'][0], $options['orderBy'][1]);
            }
        }

        $this->setObjects($qb->getQuery()->execute());
        $valueOptions = $this->getValueOptions();

        $view = new JsonModel($valueOptions);
        return $view;
    }

    private function getValueOptions() {
        $proxy = $this->getProxy();
        $targetClass = $proxy->getTargetClass();
        $metadata = $this->getOm()->getClassMetadata($targetClass);
        $identifier = $metadata->getIdentifierFieldNames();
        $objects = $this->getObjects();
        $options = array();

        if ($proxy->getDisplayEmptyItem() || empty($objects)) {
            $options[] = array('value' => null, 'label' => $proxy->getEmptyItemLabel());
        }

        if (!empty($objects)) {
            $entityOptions = $this->getOptions();
            foreach ($objects as $key => $object) {
                if (isset($entityOptions['label_generator']) && is_callable($entityOptions['label_generator']) && null !== ($generatedLabel = call_user_func($entityOptions['label_generator'], $object))) {
                    $label = $generatedLabel;
                } elseif ($property = $proxy->getProperty()) {

                    $getter = 'get' . ucfirst($property);

                    if ($proxy->getIsMethod() == false && !$metadata->hasField($property) && !is_callable(array($object, $getter))) {
                        throw new RuntimeException(
                            sprintf(
                                'Property "%s" could not be found in object "%s"', $property, $targetClass
                            )
                        );
                    }

                    if (!is_callable(array($object, $getter))) {
                        throw new RuntimeException(
                            sprintf('Method "%s::%s" is not callable', $proxy->getTargetClass(), $getter)
                        );
                    }

                    $label = $object->{$getter}();
                } else {
                    if (!is_callable(array($object, '__toString'))) {
                        throw new RuntimeException(
                            sprintf(
                                '%s must have a "__toString()" method defined if you have not set a property'
                                . ' or method to use.', $targetClass
                            )
                        );
                    }

                    $label = (string) $object;
                }

                if (count($identifier) > 1) {
                    $value = $key;
                } else {
                    $value = current($metadata->getIdentifierValues($object));
                }

                $options[] = array('label' => $label, 'value' => $value);
            }
        }

        return $options;
    }

    public function getProxy() {
        return $this->proxy;
    }

    public function getObjects() {
        return $this->objects;
    }

    public function setProxy($proxy) {
        $this->proxy = $proxy;
        return $this;
    }

    public function setObjects($objects) {
        $this->objects = $objects;
        return $this;
    }

    /**
     * @return EntityManager
     */
    public function getOm() {
        return $this->om;
    }

    public function setOm($om) {
        $this->om = $om;
        return $this;
    }

    public function getOptions() {
        return $this->options;
    }

    public function setOptions($options) {
        $this->options = $options;
        return $this;
    }

}
