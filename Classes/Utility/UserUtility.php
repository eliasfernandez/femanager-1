<?php
declare(strict_types=1);
namespace In2code\Femanager\Utility;

use In2code\Femanager\Domain\Model\User;
use In2code\Femanager\Domain\Model\UserGroup;
use In2code\Femanager\Domain\Repository\UserRepository;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Saltedpasswords\Salt\SaltFactory;
use TYPO3\CMS\Saltedpasswords\Utility\SaltedPasswordsUtility;

/**
 * Class UserUtility
 */
class UserUtility extends AbstractUtility
{

    /**
     * Return current logged in fe_user
     *
     * @return User|null
     */
    public static function getCurrentUser()
    {
        if (self::getPropertyFromUser() !== null) {
            /** @var UserRepository $userRepository */
            $userRepository = GeneralUtility::makeInstance(ObjectManager::class)->get(UserRepository::class);
            return $userRepository->findByUid((int)self::getPropertyFromUser());
        }
        return null;
    }

    /**
     * Get property from current logged in Frontend User
     *
     * @param string $propertyName
     * @return string|null
     */
    public static function getPropertyFromUser($propertyName = 'uid')
    {
        if (!empty(self::getTypoScriptFrontendController()->fe_user->user[$propertyName])) {
            return self::getTypoScriptFrontendController()->fe_user->user[$propertyName];
        }
        return null;
    }

    /**
     * Get Usergroups from current logged in user
     *
     *  array(
     *      1,
     *      5,
     *      7
     *  )
     *
     * @return array
     */
    public static function getCurrentUsergroupUids()
    {
        $currentLoggedInUser = self::getCurrentUser();
        $usergroupUids = [];
        if ($currentLoggedInUser !== null) {
            foreach ($currentLoggedInUser->getUsergroup() as $usergroup) {
                $usergroupUids[] = $usergroup->getUid();
            }
        }
        return $usergroupUids;
    }

    /**
     * Autogenerate username and password if it's empty
     *
     * @param User $user
     * @return User $user
     */
    public static function fallbackUsernameAndPassword(User $user)
    {
        $settings = self::getConfigurationManager()->getConfiguration(
            ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
            'Femanager',
            'Pi1'
        );
        $autogenerateSettings = $settings['new']['misc']['autogenerate'];
        if (!$user->getUsername()) {
            $user->setUsername(
                StringUtility::getRandomString(
                    $autogenerateSettings['username']['length'],
                    $autogenerateSettings['username']['addUpperCase'],
                    $autogenerateSettings['username']['addSpecialCharacters']
                )
            );
            if ($user->getEmail()) {
                $user->setUsername($user->getEmail());
            }
        }
        if (!$user->getPassword()) {
            $password = StringUtility::getRandomString(
                $autogenerateSettings['password']['length'],
                $autogenerateSettings['password']['addUpperCase'],
                $autogenerateSettings['password']['addSpecialCharacters']
            );
            $user->setPassword($password);
            $user->setPasswordAutoGenerated($password);
        }
        return $user;
    }

    /**
     * @param User $user
     * @param array $settings
     * @return User
     */
    public static function takeEmailAsUsername(User $user, array $settings)
    {
        if ($settings['new']['fillEmailWithUsername'] === '1') {
            $user->setEmail($user->getUsername());
        }
        return $user;
    }

    /**
     * Overwrite usergroups from user by flexform settings
     *
     * @param User $user
     * @param array $settings
     * @param string $controllerName
     * @return User $object
     */
    public static function overrideUserGroup(User $user, $settings, $controllerName = 'new')
    {
        if (!empty($settings[$controllerName]['overrideUserGroup'])) {
            $user->removeAllUsergroups();
            $usergroupUids = GeneralUtility::trimExplode(',', $settings[$controllerName]['overrideUserGroup'], true);
            foreach ($usergroupUids as $usergroupUid) {
                /** @var UserGroup $usergroup */
                $usergroup = self::getUserGroupRepository()->findByUid($usergroupUid);
                $user->addUsergroup($usergroup);
            }
        }

        return $user;
    }

    /**
     * Convert password to md5 or sha1 hash
     *
     * @param User $user
     * @param string $method
     * @return void
     */
    public static function convertPassword(User $user, $method)
    {
        if (array_key_exists('password', UserUtility::getDirtyPropertiesFromUser($user))) {
            self::hashPassword($user, $method);
        }
    }

    /**
     * Hash a password from $user->getPassword()
     *
     * @param User $user
     * @param string $method "md5", "sha1" or "none"
     * @return void
     */
    public static function hashPassword(User &$user, $method)
    {
        switch ($method) {
            case 'none':
                break;

            case 'md5':
                $user->setPassword(md5($user->getPassword()));
                break;

            case 'sha1':
                $user->setPassword(sha1($user->getPassword()));
                break;

            default:
                if (ExtensionManagementUtility::isLoaded('saltedpasswords')) {
                    if (SaltedPasswordsUtility::isUsageEnabled('FE')) {
                        $objInstanceSaltedPw = SaltFactory::getSaltingInstance();
                        $user->setPassword($objInstanceSaltedPw->getHashedPassword($user->getPassword()));
                    }
                }
        }
    }

    /**
     * Get changed properties (compare two objects with same getter methods)
     *
     * @param User $changedObject
     * @return array
     *            [firstName][old] = Alex
     *            [firstName][new] = Alexander
     */
    public static function getDirtyPropertiesFromUser(User $changedObject)
    {
        $dirtyProperties = [];
        $ignoreProperties = [
            'txFemanagerChangerequest',
            'ignoreDirty',
            'isOnline',
            'lastlogin'
        ];

        foreach ($changedObject->_getCleanProperties() as $propertyName => $oldPropertyValue) {
            if (method_exists($changedObject, 'get' . ucfirst($propertyName))
                && !in_array($propertyName, $ignoreProperties)
            ) {
                $newPropertyValue = $changedObject->{'get' . ucfirst($propertyName)}();
                if (!is_object($oldPropertyValue) || !is_object($newPropertyValue)) {
                    if ($oldPropertyValue !== $newPropertyValue) {
                        $dirtyProperties[$propertyName]['old'] = $oldPropertyValue;
                        $dirtyProperties[$propertyName]['new'] = $newPropertyValue;
                    }
                } else {
                    if (get_class($oldPropertyValue) === 'DateTime') {
                        /** @var $oldPropertyValue \DateTime */
                        /** @var $newPropertyValue \DateTime */
                        if ($oldPropertyValue->getTimestamp() !== $newPropertyValue->getTimestamp()) {
                            $dirtyProperties[$propertyName]['old'] = $oldPropertyValue->getTimestamp();
                            $dirtyProperties[$propertyName]['new'] = $newPropertyValue->getTimestamp();
                        }
                    } else {
                        $titlesOld = ObjectUtility::implodeObjectStorageOnProperty($oldPropertyValue);
                        $titlesNew = ObjectUtility::implodeObjectStorageOnProperty($newPropertyValue);
                        if ($titlesOld !== $titlesNew) {
                            $dirtyProperties[$propertyName]['old'] = $titlesOld;
                            $dirtyProperties[$propertyName]['new'] = $titlesNew;
                        }
                    }
                }
            }
        }
        return $dirtyProperties;
    }

    /**
     * overwrite user with old values and xml with new values
     *
     * @param User $user
     * @param array $dirtyProperties
     * @return User $user
     */
    public static function rollbackUserWithChangeRequest($user, $dirtyProperties)
    {
        $existingProperties = $user->_getCleanProperties();

        // reset old values
        $user->setUserGroup($existingProperties['usergroup']);
        foreach ($dirtyProperties as $propertyName => $propertyValue) {
            $propertyValue = null;
            $user->{'set' . ucfirst($propertyName)}($existingProperties[$propertyName]);
        }

        // store changes as xml in field fe_users.tx_femanager_changerequest
        $user->setTxFemanagerChangerequest(GeneralUtility::array2xml($dirtyProperties, '', 0, 'changes'));

        return $user;
    }

    /**
     * Remove FE Session to a given user
     *
     * @param User $user
     * @return void
     */
    public static function removeFrontendSessionToUser(User $user)
    {
        self::getDatabaseConnection()->exec_DELETEquery('fe_sessions', 'ses_userid = ' . (int)$user->getUid());
    }

    /**
     * Check if FE Session exists
     *
     * @param User $user
     * @return bool
     */
    public static function checkFrontendSessionToUser(User $user)
    {
        $select = 'ses_id';
        $from = 'fe_sessions';
        $where = 'ses_userid = ' . (int)$user->getUid();
        $res = self::getDatabaseConnection()->exec_SELECTquery($select, $from, $where);
        $row = self::getDatabaseConnection()->sql_fetch_assoc($res);
        return !empty($row['ses_id']);
    }

    /**
     * Login FE-User
     *
     * @param User $user
     * @param null|string $storagePids
     * @return void
     */
    public static function login(User $user, $storagePids = null)
    {
        $tsfe = self::getTypoScriptFrontendController();
        $tsfe->fe_user->checkPid = false;
        $info = $tsfe->fe_user->getAuthInfoArray();

        $extraWhere = ' AND uid = ' . (int)$user->getUid();
        if (!empty($storagePids)) {
            $extraWhere = ' AND pid IN (' . self::getDatabaseConnection()->cleanIntList($storagePids) . ')';
        }
        $user = $tsfe->fe_user->fetchUserRecord($info['db_user'], $user->getUsername(), $extraWhere);
        $tsfe->fe_user->createUserSession($user);
        $tsfe->fe_user->user = $tsfe->fe_user->fetchUserSession();
        $tsfe->fe_user->setAndSaveSessionData('ses', true);
    }
}
