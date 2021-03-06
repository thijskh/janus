<?php

namespace Janus\ServiceRegistry\Bundle\CoreBundle\Form\Type;

use Janus\ServiceRegistry\Bundle\CoreBundle\DependencyInjection\ConfigProxy;
use Janus\ServiceRegistry\Bundle\CoreBundle\Form\Type\Connection\ArpAttributesType;
use Janus\ServiceRegistry\Bundle\CoreBundle\Form\Type\Connection\ConnectionTypeType;
use Janus\ServiceRegistry\Connection\ConnectionDto;
use Janus\ServiceRegistry\Entity\Connection;

use Janus\ServiceRegistry\Connection\Metadata\ConfigFieldsParser;
use Janus\ServiceRegistry\Bundle\CoreBundle\Form\Type\Connection\MetadataType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class ConnectionType extends AbstractType
{
    /** @var \Janus\ServiceRegistry\Connection\Metadata\ConfigFieldsParser */
    protected $configFieldsParser;

    /** @var  ConfigProxy */
    protected $janusConfig;

    /**
     * @param ConfigProxy $janusConfig
     */
    public function __construct(ConfigProxy $janusConfig)
    {
        $this->janusConfig = $janusConfig;
        $this->configFieldsParser = new ConfigFieldsParser();
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('name', 'text');
        $builder->add('state', 'choice', array(
            'choices' => array(
                'testaccepted' => 'Test Accepted',
                'prodaccepted' => 'Prod Accepted'
            )
        ));
        $builder->add('type', new ConnectionTypeType());

        $builder->add('expirationDate', 'datetime', array(
            'required' => false
        ));
        $builder->add('metadataUrl', 'text', array(
            'required' => false
        ));
        $builder->add('metadataValidUntil', 'datetime', array(
            'required' => false
        ));
        $builder->add('metadataCacheUntil', 'datetime', array(
            'required' => false
        ));
        $builder->add('allowAllEntities', 'checkbox');
        $builder->add('arpAttributes', new ArpAttributesType($this->janusConfig));

        // START EVIL HACK
        // Forces NULL values for arpAttributes.
        // We need this because ArpAttributes are disabled when they have a null value.
        // But Symfony Forms requires all forms that have children to use an empty array.
        $forceNull = false;
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function(FormEvent $event) use (&$forceNull) {
            $eventData = $event->getData();
            $forceNull = (!isset($eventData['arpAttributes']) || $eventData['arpAttributes'] === null);
        });
        $builder->addEventListener(FormEvents::POST_SUBMIT, function(FormEvent $event) use (&$forceNull) {
            if (!$forceNull) {
                return;
            }

            /** @var ConnectionDto $connectionDto */
            $connectionDto = $event->getData();
            $connectionDto->arpAttributes = null ;

            $forceNull = false;
        });
        // END EVIL HACK

        $builder->add('manipulationCode', 'textarea', array(
            'required' => false
        ));
        $builder->add('revisionNote', 'textarea');
        $builder->add('notes', 'textarea', array(
            'required' => false
        ));
        $builder->add('isActive', 'checkbox');

        $builder->add('allowedConnections'  , 'collection', array(
            'type' => new ConnectionReferenceType(),
            'allow_add' => true,
        ));
        $builder->add('blockedConnections'  , 'collection', array(
            'type' => new ConnectionReferenceType(),
            'allow_add' => true,
        ));
        $builder->add('disableConsentConnections', 'collection', array(
            'type' => new ConnectionReferenceType(),
            'allow_add' => true,
        ));

        // Ignore these fields:
        $builder->add('active'              , 'hidden', array('mapped' => false));
        $builder->add('createdAtDate'       , 'hidden', array('mapped' => false));
        $builder->add('updatedAtDate'       , 'hidden', array('mapped' => false));
        $builder->add('id'                  , 'hidden', array('mapped' => false));
        $builder->add('revisionNr'          , 'hidden', array('mapped' => false));
        $builder->add('updatedByUserName'   , 'hidden', array('mapped' => false));
        $builder->add('updatedFromIp'       , 'hidden', array('mapped' => false));
        $builder->add('parentRevisionNr'    , 'hidden');

        if (!isset($options['data'])) {
            throw new \RuntimeException(
                "No data set"
            );
        }
        /** @var ConnectionDto $data */
        $data = $options['data'];
        if (!$data->type) {
            throw new \RuntimeException(
                'No "type" in input! I need a type to detect which metadatafields should be required.'
            );
        }
        $this->addMetadataFields($builder, $this->janusConfig, $data->type, $options);
    }

    /**
     * Adds metadata field with type dependant config
     *
     * @param FormBuilderInterface $builder
     * @param ConfigProxy $janusConfig
     * @param $connectionType
     */
    protected function addMetadataFields(
        FormBuilderInterface $builder,
        ConfigProxy $janusConfig,
        $connectionType
    ) {
        $metadataFieldsConfig = $this->getMetadataFieldsConfig($janusConfig, $connectionType);

        $metadataFormTypeOptions = array();
        $builder->add(
            $builder->create('metadata', new MetadataType($metadataFieldsConfig), $metadataFormTypeOptions)
        );
    }

    /**
     * @param ConfigProxy $janusConfig
     * @param $connectionType
     * @return array
     */
    protected function getMetadataFieldsConfig(ConfigProxy $janusConfig, $connectionType)
    {
        // Get the configuration for the metadata fields from the Janus configuration
        $janusMetadataFieldsConfig = $this->findJanusMetadataConfig($janusConfig, $connectionType);

        // Convert it to hierarchical structure that we can use to build a form.
        $metadataFieldsConfig = $this->configFieldsParser->parse($janusMetadataFieldsConfig)->getChildren();
        return $metadataFieldsConfig;
    }

    /**
     * @param ConfigProxy $janusConfig
     * @param $connectionType
     * @return mixed
     * @throws \Exception
     */
    protected function findJanusMetadataConfig(ConfigProxy $janusConfig, $connectionType)
    {
        $configKey = "metadatafields.{$connectionType}";
        if (!$janusConfig->hasValue($configKey)) {
            throw new \Exception("No metadatafields config found for type {$connectionType}");
        }

        $metadataFieldsConfig = $janusConfig->getArray($configKey);
        return $metadataFieldsConfig;
    }

    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => '\Janus\ServiceRegistry\Connection\ConnectionDto',
            'intention' => 'connection',
            'translation_domain' => 'JanusServiceRegistryBundle',
            'extra_fields_message' => 'This form should not contain these extra fields: "{{ extra_fields }}"',
            'csrf_protection' => false,
        ));
    }

    public function getName()
    {
        return 'connection';
    }
}
