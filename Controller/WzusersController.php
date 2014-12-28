<?php
/**
 * The MIT License (MIT)
 *
 * Webzash - Easy to use web based double entry accounting software
 *
 * Copyright (c) 2014 Prashant Shah <pshah.mumbai@gmail.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

App::uses('WebzashAppController', 'Webzash.Controller');
App::uses('ConnectionManager', 'Model');
App::uses('JoomlaAuth', 'Webzash.Lib');

/**
 * Webzash Plugin Wzusers Controller
 *
 * @package Webzash
 * @subpackage Webzash.controllers
 */
class WzusersController extends WebzashAppController {

	public $uses = array('Webzash.Wzuser', 'Webzash.Wzaccount',
		'Webzash.Wzuseraccount', 'Webzash.Wzsetting');

	var $layout = 'admin';

/**
 * index method
 *
 * @return void
 */
	public function index() {

		$this->set('title_for_layout', __d('webzash', 'Users'));

		$this->Wzuser->useDbConfig = 'wz';

		$this->set('actionlinks', array(
			array('controller' => 'wzusers', 'action' => 'add', 'title' => __d('webzash', 'Add User')),
			array('controller' => 'admin', 'action' => 'index', 'title' => __d('webzash', 'Back')),
		));

		$this->Paginator->settings = array(
			'Wzuser' => array(
				'limit' => $this->Session->read('Wzsetting.row_count'),
				'order' => array('Wzuser.username' => 'asc'),
			)
		);

		$this->set('wzusers', $this->Paginator->paginate('Wzuser'));

		return;
	}

/**
 * add method
 *
 * @return void
 */
	public function add() {
		$joomla = new JoomlaAuth();
		return $this->redirect($joomla->siteURL());
	}


/**
 * edit method
 *
 * @param string $id
 * @return void
 */
	public function edit($id = null) {

		$this->set('title_for_layout', __d('webzash', 'Edit User'));

		$this->Wzuser->useDbConfig = 'wz';
		$this->Wzaccount->useDbConfig = 'wz';
		$this->Wzuseraccount->useDbConfig = 'wz';
		$this->Wzsetting->useDbConfig = 'wz';

		$wzsetting = $this->Wzsetting->findById(1);
		if (!$wzsetting) {
			$this->Session->setFlash(__d('webzash', 'Please update your settings below before editing any user.'), 'danger');
			return $this->redirect(array('plugin' => 'webzash', 'controller' => 'wzsettings', 'action' => 'edit'));
		}

		/* Check for valid user */
		if (empty($id)) {
			$this->Session->setFlash(__d('webzash', 'User account not specified.'), 'danger');
			return $this->redirect(array('plugin' => 'webzash', 'controller' => 'wzusers', 'action' => 'index'));
		}
		$wzuser = $this->Wzuser->findById($id);
		if (!$wzuser) {
			$this->Session->setFlash(__d('webzash', 'User account not found.'), 'danger');
			return $this->redirect(array('plugin' => 'webzash', 'controller' => 'wzusers', 'action' => 'index'));
		}

		/* Create list of wzaccounts */
		$wzaccounts = array(0 => '(ALL ACCOUNTS)') + $this->Wzaccount->find('list', array(
			'fields' => array('Wzaccount.id', 'Wzaccount.label'),
			'order' => array('Wzaccount.label')
		));
		$this->set('wzaccounts', $wzaccounts);

		/* on POST */
		if ($this->request->is('post') || $this->request->is('put')) {
			/* Set user id */
			unset($this->request->data['Wzuser']['id']);

			$this->Wzuser->id = $id;

			/* Check if user is allowed access to all accounts */
			if (!empty($this->request->data['Wzuser']['wzaccount_ids'])) {
				if (in_array(0, $this->request->data['Wzuser']['wzaccount_ids'])) {
					$this->request->data['Wzuser']['all_accounts'] = 1;
				} else {
					$this->request->data['Wzuser']['all_accounts'] = 0;
				}
			} else {
				$this->request->data['Wzuser']['wzaccount_ids'] = array();
				$this->request->data['Wzuser']['all_accounts'] = 0;
			}

			/* Save user */
			$ds = $this->Wzuser->getDataSource();
			$ds->begin();

			$this->request->data['Wzuser']['verification_key'] = Security::hash(uniqid() . uniqid());
			$this->request->data['Wzuser']['retry_count'] = 0;

			if ($this->Wzuser->save($this->request->data, true, array('username', 'fullname', 'email', 'role', 'status', 'email_verified', 'admin_verified', 'verification_key', 'retry_count', 'all_accounts'))) {

				/* Delete existing user - account associations */
				if (!$this->Wzuseraccount->deleteAll(array('Wzuseraccount.wzuser_id' => $id))) {
					$ds->rollback();
					$this->Session->setFlash(__d('webzash', 'Failed to update user account. Please, try again.'), 'danger');
					return $this->redirect(array('plugin' => 'webzash', 'controller' => 'wzusers', 'action' => 'index'));
				}

				/* Save user - accounts association */
				if ($this->request->data['Wzuser']['all_accounts'] != 1) {
					if (!empty($this->request->data['Wzuser']['wzaccount_ids'])) {
						$data = array();
						foreach ($this->request->data['Wzuser']['wzaccount_ids'] as $row => $wzaccount_id) {
							if (!$this->Wzaccount->exists($wzaccount_id)) {
								continue;
							}
							$data[] = array('wzuser_id' => $this->Wzuser->id, 'wzaccount_id' => $wzaccount_id, 'role' => '');
						}
						if (!$this->Wzuseraccount->saveMany($data)) {
							$ds->rollback();
							$this->Session->setFlash(__d('webzash', 'Failed to update user account. Please, try again.'), 'danger');
							return $this->redirect(array('plugin' => 'webzash', 'controller' => 'wzusers', 'action' => 'index'));
						}
					}
				}

				$ds->commit();
				$this->Session->setFlash(__d('webzash', 'User account updated.'), 'success');
				return $this->redirect(array('plugin' => 'webzash', 'controller' => 'wzusers', 'action' => 'index'));
			} else {
				$ds->rollback();
				$this->Session->setFlash(__d('webzash', 'Failed to update user account. Please, try again.'), 'danger');
				return;
			}
		} else {
			$this->request->data = $wzuser;

			/* Load existing user - account association */
			if ($wzuser['Wzuser']['all_accounts'] == 1) {
				$this->request->data['Wzuser']['wzaccount_ids'] = array('0');
			} else {
				$rawuseraccounts = $this->Wzuseraccount->find('all',
					array('conditions' => array('Wzuseraccount.wzuser_id' => $id))
				);
				$useraccounts = array();
				foreach ($rawuseraccounts as $row => $useraccount) {
					$useraccounts[] = $useraccount['Wzuseraccount']['wzaccount_id'];
				}
				$this->request->data['Wzuser']['wzaccount_ids'] = $useraccounts;
			}
			return;
		}
	}

/**
 * delete method
 *
 * @throws MethodNotAllowedException
 * @param string $id
 * @return void
 */
	public function delete($id = null) {
		/* GET access not allowed */
		if ($this->request->is('get')) {
			throw new MethodNotAllowedException();
		}

		$this->Wzuser->useDbConfig = 'wz';
		$this->Wzuseraccount->useDbConfig = 'wz';

		/* Check if valid id */
		if (empty($id)) {
			$this->Session->setFlash(__d('webzash', 'User account not specified.'), 'danger');
			return $this->redirect(array('plugin' => 'webzash', 'controller' => 'wzusers', 'action' => 'index'));
		}

		/* Check if user exists */
		if (!$this->Wzuser->exists($id)) {
			$this->Session->setFlash(__d('webzash', 'User account not found.'), 'danger');
			return $this->redirect(array('plugin' => 'webzash', 'controller' => 'wzusers', 'action' => 'index'));
		}

		/* Cannot delete your own account */
		if ($id == $this->Auth->user('id')) {
			$this->Session->setFlash(__d('webzash', 'Cannot delete own account.'), 'danger');
			return $this->redirect(array('plugin' => 'webzash', 'controller' => 'wzusers', 'action' => 'index'));
		}

		/* Delete user */
		$ds = $this->Wzuser->getDataSource();
		$ds->begin();

		if (!$this->Wzuser->delete($id)) {
			$ds->rollback();
			$this->Session->setFlash(__d('webzash', 'Failed to delete user account. Please, try again.'), 'danger');
			return $this->redirect(array('plugin' => 'webzash', 'controller' => 'wzusers', 'action' => 'index'));
		}

		/* Delete user - account association */
		if (!$this->Wzuseraccount->deleteAll(array('Wzuseraccount.wzuser_id' => $id))) {
			$ds->rollback();
			$this->Session->setFlash(__d('webzash', 'Failed to delete user account. Please, try again.'), 'danger');
			return $this->redirect(array('plugin' => 'webzash', 'controller' => 'wzusers', 'action' => 'index'));
		}

		/* Success */
		$ds->commit();
		$this->Session->setFlash(__d('webzash', 'User account deleted.'), 'success');

		return $this->redirect(array('plugin' => 'webzash', 'controller' => 'wzusers', 'action' => 'index'));
	}

/**
 * login method
 */
	public function login() {

		$this->set('title_for_layout', __d('webzash', 'User Login'));

		$this->layout = 'user';

		$view = new View($this);
		$this->Html = $view->loadHelper('Html');

		$this->Wzuser->useDbConfig = 'wz';
		$this->Wzsetting->useDbConfig = 'wz';

		/* on POST */
		if ($this->request->is('post') || $this->request->is('put')) {

			$joomla = new JoomlaAuth();
			$login_status = $joomla->checkPassword(
				$this->request->data['Wzuser']['username'],
				$this->request->data['Wzuser']['password']);

			if ($login_status) {

				$wzuser = $this->Wzuser->find('first', array('conditions' => array(
					'username' => $this->request->data['Wzuser']['username'],
				)));

				$user_data = array();
				if ($wzuser) {
					$user_data = array(
						'id' => $wzuser['Wzuser']['id'],
						'username' => $wzuser['Wzuser']['username'],
						'role' => $wzuser['Wzuser']['role'],
					);
				} else {
					/* if user not found create a account */
					$new_user['Wzuser'] = array(
						'username' => $this->request->data['Wzuser']['username'],
						'password' => '*',
						'fullname' => '',
						'email' => '',
						'timezone' => 'UTC',
						'role' => 'guest',
						'status' => 0,
						'verification_key' => '',
						'email_verified' => 0,
						'admin_verified' => 0,
						'retry_count' => 0,
						'all_accounts' => 0,
					);

					/* Create user */
					$this->Wzuser->create();
					if (!$this->Wzuser->save($new_user)) {
						$this->Session->setFlash(__d('webzash', 'Failed to create user.'), 'danger');
						return;
					}

					$user_data = array(
						'id' => $this->Wzuser->id,
						'username' => $this->Wzuser->username,
						'role' => $this->Wzuser->role,
					);
				}

				$this->Auth->login($user_data);

				$wzsetting = $this->Wzsetting->findById(1);

				if (empty($wzsetting['Wzsetting']['enable_logging'])) {
					$this->Session->write('Wzsetting.enable_logging', 0);
				} else {
					$this->Session->write('Wzsetting.enable_logging', 1);
				}
				if (empty($wzsetting['Wzsetting']['row_count'])) {
					$this->Session->write('Wzsetting.row_count', 10);
				} else {
					$this->Session->write('Wzsetting.row_count', $wzsetting['Wzsetting']['row_count']);
				}
				if (empty($wzsetting['Wzsetting']['drcr_toby'])) {
					$this->Session->write('Wzsetting.drcr_toby', 'drcr');
				} else {
					$this->Session->write('Wzsetting.drcr_toby', $wzsetting['Wzsetting']['drcr_toby']);
				}

				if ($this->Auth->user('role') == 'admin') {
					return $this->redirect(array('plugin' => 'webzash', 'controller' => 'admin', 'action' => 'index'));
				} else {
					return $this->redirect($this->Auth->redirectUrl());
				}

			} else {
				$this->Session->setFlash(__d('webzash', 'Login failed. Please, try again.'), 'danger');
			}
		}
	}

/**
 * logout method
 */
	public function logout() {
		$this->Session->destroy();
		$this->Auth->logout();

		$joomla = new JoomlaAuth();
		return $this->redirect($joomla->logoutURL());
	}

/**
 * verifiy email method
 */
	public function verify() {
		$joomla = new JoomlaAuth();
		return $this->redirect($joomla->siteURL());
	}

/**
 * resend verification email method
 */
	public function resend() {
		$joomla = new JoomlaAuth();
		return $this->redirect($joomla->siteURL());
	}

/**
 * user profile method
 */
	public function profile() {
		$joomla = new JoomlaAuth();
		return $this->redirect($joomla->siteURL());
	}

/**
 * change password method
 */
	public function changepass() {
		$joomla = new JoomlaAuth();
		return $this->redirect($joomla->siteURL());
	}

/**
 * reset user password by admin method
 */
	public function resetpass() {
		$joomla = new JoomlaAuth();
		return $this->redirect($joomla->siteURL());
	}

/**
 * forgot password method
 */
	public function forgot() {
		$joomla = new JoomlaAuth();
		return $this->redirect($joomla->siteURL());
	}

/**
 * register user method
 */
	public function register() {
		$joomla = new JoomlaAuth();
		return $this->redirect($joomla->siteURL());
	}

/**
 * first time login for admin user
 */
	public function first() {
		$joomla = new JoomlaAuth();
		return $this->redirect($joomla->siteURL());
	}

/**
 * change active account
 */
	public function account() {

		$this->set('title_for_layout', __d('webzash', 'Select account to activate'));

		$this->layout = 'default';

		$this->Wzuser->useDbConfig = 'wz';
		$this->Wzaccount->useDbConfig = 'wz';
		$this->Wzuseraccount->useDbConfig = 'wz';

		$wzuser = $this->Wzuser->findById($this->Auth->user('id'));
		if (!$wzuser) {
			$this->Session->setFlash(__d('webzash', 'User not found.'), 'danger');
			return;
		}

		/* Currently active account */
		$curActiveAccount = $this->Wzaccount->findById($this->Session->read('ActiveAccount.id'));
		if ($curActiveAccount) {
			$this->set('curActiveAccount', $curActiveAccount['Wzaccount']['label']);
		} else {
			$this->set('curActiveAccount', '(NONE)');
		}

		$wzaccounts_count = $this->Wzaccount->find('count');
		$this->set('wzaccounts_count', $wzaccounts_count);

		/* Create list of wzaccounts */
		if ($wzuser['Wzuser']['all_accounts'] == 1) {
			$wzaccounts = $this->Wzaccount->find('list', array(
				'fields' => array('Wzaccount.id', 'Wzaccount.label'),
				'order' => array('Wzaccount.label')
			));
			$wzaccounts = array(0 => '(NONE)') + $wzaccounts;
		} else {
			$wzaccounts = array();
			$rawwzaccounts = $this->Wzuseraccount->find('all', array(
				'conditions' => array('Wzuseraccount.wzuser_id' => $this->Auth->user('id')),
			));
			foreach ($rawwzaccounts as $row => $wzaccount) {
				$account = $this->Wzaccount->findById($wzaccount['Wzuseraccount']['wzaccount_id']);
				if ($account) {
					$wzaccounts[$account['Wzaccount']['id']] = $account['Wzaccount']['label'];
				}
			}
			$wzaccounts = array(0 => '(NONE)') + $wzaccounts;
		}
		$this->set('wzaccounts', $wzaccounts);

		if ($this->Session->read('ActiveAccount.failed')) {
			$this->Session->setFlash(__d('webzash', 'Failed to connect to account database. Please check your connection settings.'), 'danger');
			$this->Session->delete('ActiveAccount.failed');
			return;
		}

		/* On POST */
		if ($this->request->is('post') || $this->request->is('put')) {

			/* Check if NONE selected */
			if ($this->request->data['Wzuser']['wzaccount_id'] == 0) {
				$this->Session->delete('ActiveAccount.id');
				$this->Session->delete('ActiveAccount.account_role');
				$this->Session->setFlash(__d('webzash', 'All accounts deactivated.'), 'success');
				return $this->redirect(array('plugin' => 'webzash', 'controller' => 'wzusers', 'action' => 'account'));
			}

			/* Check if user is allowed to access the account */
			$activateAccount = FALSE;
			$account_role = '';
			if ($wzuser['Wzuser']['all_accounts'] == 1) {
				$activateAccount = TRUE;
				/* Read account role */
				$temp = $this->Wzuseraccount->find('first', array(
					'conditions' => array(
						'Wzuseraccount.wzaccount_id' => $this->request->data['Wzuser']['wzaccount_id'],
					),
				));
				if ($temp) {
					$account_role = $temp['Wzuseraccount']['role'];
				} else {
					$account_role = '';
				}
			} else {
				$temp = $this->Wzuseraccount->find('first', array(
					'conditions' => array(
						'Wzuseraccount.wzuser_id' => $this->Auth->user('id'),
						'Wzuseraccount.wzaccount_id' => $this->request->data['Wzuser']['wzaccount_id'],
					),
				));
				if ($temp) {
					$activateAccount = TRUE;
					$account_role = $temp['Wzuseraccount']['role'];
				} else {
					$account_role = '';
				}
			}
			if ($activateAccount) {
				$temp = $this->Wzaccount->findById($this->request->data['Wzuser']['wzaccount_id']);
				if (!$temp) {
					$this->Session->delete('ActiveAccount.id');
					$this->Session->delete('ActiveAccount.account_role');
					$this->Session->setFlash(__d('webzash', 'Account not found.'), 'danger');
					return $this->redirect(array('plugin' => 'webzash', 'controller' => 'wzusers', 'action' => 'account'));
				}

				/* Setup account role */
				$basic_roles = array('admin', 'manager', 'accountant', 'dataentry', 'guest');
				if (in_array($account_role, $basic_roles)) {
					$this->Session->write('ActiveAccount.account_role', $account_role);
				} else {
					/* Set the account role as per user profile */
					$this->Session->write('ActiveAccount.account_role', $this->Auth->user('role'));
				}

				$this->Session->write('ActiveAccount.id', $temp['Wzaccount']['id']);
				$this->Session->setFlash(__d('webzash', 'Account "%s" activated.', $temp['Wzaccount']['label']), 'success');
				return $this->redirect(array('plugin' => 'webzash', 'controller' => 'dashboard', 'action' => 'index'));
			} else {
				$this->Session->delete('ActiveAccount.id');
				$this->Session->delete('ActiveAccount.account_role');
				$this->Session->setFlash(__d('webzash', 'Failed to activate account. Please, try again.'), 'danger');
				return $this->redirect(array('plugin' => 'webzash', 'controller' => 'wzusers', 'action' => 'account'));
			}
		} else {
			if ($curActiveAccount) {
				$this->request->data['Wzuser']['wzaccount_id'] = $this->Session->read('ActiveAccount.id');
			} else {
				$this->request->data['Wzuser']['wzaccount_id'] = 0;
			}
		}
	}

	public function beforeFilter() {
		parent::beforeFilter();
		$this->Auth->allow('login', 'logout', 'verify', 'resend', 'forgot', 'register');
	}

	/* Authorization check */
	public function isAuthorized($user) {
		if ($this->action === 'index') {
			return $this->Permission->is_admin_allowed();
		}

		if ($this->action === 'add') {
			return $this->Permission->is_admin_allowed();
		}

		if ($this->action === 'edit') {
			return $this->Permission->is_admin_allowed();
		}

		if ($this->action === 'delete') {
			return $this->Permission->is_admin_allowed();
		}

		if ($this->action === 'profile') {
			return $this->Permission->is_registered_allowed();
		}

		if ($this->action === 'changepass') {
			return $this->Permission->is_registered_allowed();
		}

		if ($this->action === 'resetpass') {
			return $this->Permission->is_admin_allowed();
		}

		if ($this->action === 'first') {
			return $this->Permission->is_admin_allowed();
		}

		if ($this->action === 'account') {
			return $this->Permission->is_registered_allowed();
		}

		return parent::isAuthorized($user);
	}
}
