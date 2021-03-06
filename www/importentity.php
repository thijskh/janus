<?php

require __DIR__ . '/_includes.php';

set_time_limit(180);

// Note: creating the security context also sets the token which is necessary
// for Doctrine to set the authenticated user on updating an entity
// Since this can be easily forgotten in the current 'page script' setup
// This seems to be one more reason to handle routing and access control
// by a central framework, seel also: https://github.com/janus-ssp/janus/issues/477
sspmod_janus_DiContainer::getInstance()->getSecurityContext();

// Initial import
$session = SimpleSAML_Session::getInstance();
$config = SimpleSAML_Configuration::getInstance();
$janusConfig = sspmod_janus_DiContainer::getInstance()->getConfig();
$csrf_provider = sspmod_janus_DiContainer::getInstance()->getCsrfProvider();

// Get data from config
/** @var $authenticationSource string */
$authenticationSource = $janusConfig->getValue('auth', 'login-admin');
/** @var $userIdAttribute string */
$userIdAttribute = $janusConfig->getValue('useridattr', 'eduPersonPrincipalName');

// Validate user
if ($session->isValid($authenticationSource)) {
    $attributes = $session->getAttributes();
    // Check if user id exists
    if (!isset($attributes[$userIdAttribute])) {
        throw new Exception('User ID is missing');
    }
    $userid = $attributes[$userIdAttribute][0];
    $user = new sspmod_janus_User($janusConfig->getValue('store'));
    $user->setUserid($userid);
    $user->load(sspmod_janus_User::USERID_LOAD);
} else {
    $session->setData('string', 'refURL', SimpleSAML_Utilities::selfURL());
    SimpleSAML_Utilities::redirectTrustedUrl(SimpleSAML_Module::getModuleURL('janus/index.php'));
    exit;
}

$importData = $session->getData('string', 'import');
$importType = $session->getData('string', 'import_type');

if (!$importData && !$importType) {
    throw new SimpleSAML_Error_Exception('Nothing to import!');
}

if (!isset($_GET['eid'])) {
    throw new SimpleSAML_Error_Exception('No entity selected!');
}

// Revision not set, get latest
$entityController = sspmod_janus_DiContainer::getInstance()->getEntityController();
$entity = $entityController->setEntity(
    (string)(int)$_GET['eid']
);
if (!$entity) {
    throw new SimpleSAML_Error_Exception('Faulty entity selected');
}

$update = false;
$msg = '';
$note = '';

$converter = sspmod_janus_DiContainer::getInstance()->getMetaDataConverter();
$oldMetadata = $converter->execute($entityController->getMetaArray());

$et = new SimpleSAML_XHTML_Template($config, 'janus:importentity.php', 'janus:editentity');
$et->data['old'] = $oldMetadata;
$et->data['oldAcl'] = array(
    'AllowedAll' => $entityController->getAllowedAll(),
    'Allowed' => array_map(function ($allowedEntity) use ($janusConfig) {
        // @todo this is very inefficient for large sets
        $controller = sspmod_janus_DiContainer::getInstance()->getEntityController();
        $controller->setEntity($allowedEntity['remoteeid']);
        return $controller->getEntity()->getPrettyname();
    }, $entityController->getAllowedEntities()),
    'Blocked' => array_map(function ($blockedEntity) use ($janusConfig) {
        // @todo this is very inefficient for large sets
        $controller = sspmod_janus_DiContainer::getInstance()->getEntityController();
        $controller->setEntity($blockedEntity['remoteeid']);
        return $controller->getEntity()->getPrettyname();
    }, $entityController->getBlockedEntities()),
);

$excludedMetadataKeys = array();
if (isset($_POST['excluded_metadata_keys'])) {
    $excludedMetadataKeys = $_POST['excluded_metadata_keys'];
}

if ($importType === 'xml') {
    if ($entity->getType() === 'saml20-sp') {
        $msg = $entityController->importMetadata20SP($importData, $update, $excludedMetadataKeys);
    } else if ($entity->getType() === 'saml20-idp') {
        $msg = $entityController->importMetadata20IdP($importData, $update, $excludedMetadataKeys);
    } else {
        throw new SimpleSAML_Error_Exception($entity->getType() . ' is not a valid type to import');
    }
} else if ($importType === 'json') {
    try {
        $metaStdClass = json_decode($importData);
        if ($metaStdClass) {
            $metaArray = convert_stdobject_to_array($metaStdClass);
            $metaArrayFlat = $converter->execute($metaArray);
            if ($metaArrayFlat['entityid'] === $entityController->getEntity()->getEntityid()) {
                foreach ($metaArrayFlat as $key => $value) {
                    if (!empty($excludedMetadataKeys) && in_array($key, $excludedMetadataKeys)) {
                        continue;
                    }
                    if ($entityController->hasMetadata($key)) {
                        $entityController->updateMetadata($key, $value);
                    } else {
                        $entityController->addMetadata($key, $value);
                    }
                }

                $entityController->setAllowedAll('no');
                $entityController->clearAllowedEntities();
                $entityController->clearBlockedEntities();
                if (isset($metaArray['allowed'])) {
                    foreach ($metaArray['allowed'] as $allowedEntityId) {
                        $allowedEntityController = sspmod_janus_DiContainer::getInstance()->getEntityController();
                        $allowedEntityController->setEntity($allowedEntityId);
                        $entityController->addAllowedEntity($allowedEntityController->getEntity()->getEid());
                    }
                }

                if (isset($metaArray['blocked'])) {
                    foreach ($metaArray['blocked'] as $blockedEntityId) {
                        $allowedEntityController = sspmod_janus_DiContainer::getInstance()->getEntityController();
                        $allowedEntityController->setEntity($blockedEntityId);
                        $entityController->addAllowedEntity($allowedEntityController->getEntity()->getEid());
                    }
                }
                $update = TRUE;
                $msg = 'status_metadata_parsed_ok';
            } else {
                $msg = 'error_metadata_wrong_entity';
            }
        } else {
            $msg = 'error_not_valid_json';
        }
    } catch (Exception $e) {
        $msg = 'error_metadata_not_parsed';
    }
} else {
    throw new SimpleSAML_Error_Exception("Unknown import type: '$importType'");
}

if (!empty($_POST) && isset($_POST['apply'])) {
    if (!isset($_POST['csrf_token']) || !$csrf_provider->isCsrfTokenValid('import_entity', $_POST['csrf_token'])) {
        SimpleSAML_Logger::warning('Janus: [SECURITY] CSRF token not found or invalid');
        throw new SimpleSAML_Error_BadRequest('Missing valid csrf token!');
    }
    // Update entity if updated
    if ($update) {
        $entityController->saveEntity();
        $entityController->loadEntity();
        $entity = $entityController->getEntity();

        // Notify users who have asked to be updated when
        $pm = new sspmod_janus_Postman();
        $addresses[] = 'ENTITYUPDATE-' . $entity->getEid();
        $editLink = SimpleSAML_Module::getModuleURL(
            'janus/editentity.php',
            array(
                'eid' => $entity->getEid(),
                'revisionid' => $entity->getRevisionid())
        );
        $pm->post(
            'Entity updated - ' . $entity->getEntityid(),
            'Permalink: <a href="' . htmlspecialchars($editLink) . '">'
                . htmlspecialchars($editLink)
                . '</a><br /><br />'
                . htmlspecialchars($entity->getRevisionnote())
                . '<br /><br />'
                . htmlspecialchars($note),
            $addresses,
            $user->getUid()
        );
    }

    $session->deleteData('string', 'meta_xml');
    $session->deleteData('string', 'meta_json');

    SimpleSAML_Utilities::redirectTrustedUrl(
        SimpleSAML_Module::getModuleURL('janus/editentity.php'),
        array(
            'eid' => $entity->getEid(),
            'revisionid' => $entity->getRevisionid(),
        )
    );
    exit;
}

$et->data['update'] = $update;


$newMetadata = $converter->execute($entityController->getMetaArray());
$et->data['new'] = $newMetadata;
$et->data['newAcl'] = array(
    'AllowedAll' => $entityController->getAllowedAll(),
    'Allowed' => array_map(function ($allowedEntity) use ($janusConfig) {
        // @todo this is very inefficient for large sets
        $controller = sspmod_janus_DiContainer::getInstance()->getEntityController();
        $controller->setEntity($allowedEntity['remoteeid']);
        return $controller->getEntity()->getPrettyname();
    }, $entityController->getAllowedEntities()),
    'Blocked' => array_map(function ($blockedEntity) use ($janusConfig) {
        // @todo this is very inefficient for large sets
        $controller = sspmod_janus_DiContainer::getInstance()->getEntityController();
        $controller->setEntity($blockedEntity['remoteeid']);
        return $controller->getEntity()->getPrettyname();
    }, $entityController->getBlockedEntities()),
);

$changes = janus_array_diff_recursive($newMetadata, $oldMetadata);
$et->data['changes'] = $changes;

$et->data['header'] = 'JANUS';
$et->data['message'] = $msg;
$et->show();

function janus_array_diff_recursive($array1, $array2)
{
    $diff = array();
    foreach ($array1 as $key => $value) {
        if (array_key_exists($key, $array2)) {
            if (is_array($value)) {
                $subDiff = janus_array_diff_recursive($value, $array2[$key]);
                if (count($subDiff)) {
                    $diff[$key] = $subDiff;
                }
            } else {
                if ($value != $array2[$key]) {
                    $diff[$key] = $value;
                }
            }
        } else {
            $diff[$key] = $value;
        }
    }
    return $diff;
}

function convert_stdobject_to_array($object)
{
    $object = (array)$object;

    foreach ($object as $key => $value) {
        if (is_array($value) || (is_object($value) && get_class($value) === 'stdClass')) {
            $object[$key] = convert_stdobject_to_array($value);
        }
    }
    return $object;
}
