<?php
/**
 * iroha Compass Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2018 iroha Soft, Inc. (http://irohasoft.jp)
 * @link          http://irohacompass.irohasoft.jp
 * @license       http://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

App::uses('AppController', 'Controller');
App::uses('Record', 'Record');

/**
 * Progresss Controller
 *
 * @property Progress $Progress
 * @property PaginatorComponent $Paginator
 */
class ProgressesController extends AppController
{

	public $components = array(
		'Paginator',
		'Security' => array(
			'validatePost' => false,
			'csrfUseOnce' => false,
			'csrfCheck' => false,
			'unlockedActions' => array('admin_order')
		),
	);

	public function index($task_id, $progress_id = null)
	{
		//$this->Progress->recursive = 0;
		/*
		$progresses = $this->Progress->find('all', array(
			'conditions' => array(
				'task_id' => $task_id
			),
			'order' => array('Progress.created' => 'asc')
		));
		*/
		
		$this->Paginator->settings = array(
			'limit' => 100,
			'order' => 'Progress.created asc',
			'conditions' => array(
				'task_id' => $task_id
			),
		);
		
		//debug($this->request->params);
		
		$progresses = $this->paginate();
		
		// 管理者以外の場合、コンテンツの閲覧権限の確認
		if($this->Session->read('Auth.User.role') != 'admin')
		{
			$this->loadModel('Theme');
			
			if(count($progresses) > 0)
			{
				if(! $this->Theme->hasRight($this->Session->read('Auth.User.id'), $progresses[0]['Task']['theme_id']))
				{
					throw new NotFoundException(__('Invalid access'));
				}
			}
		}
		
		// コンテンツ情報を取得
		$this->loadModel('Task');
		$content = $this->Task->find('first', array(
			'conditions' => array(
				'Task.id' => $task_id
			)
		));
		
		$is_record = (($this->action == 'record') || ($this->action == 'admin_record'));
		$is_admin  = ($this->action == 'admin_record');
		$is_user   = ($this->action == 'index');
		
		$this->loadModel('User');
		
		for($i=0; $i < count($progresses); $i++)
		{
			$smiled_ids   = array(); // スマイルした人のID（自分以外）
			
			$smiles = $progresses[$i]['Smile'];
			
			$is_smiled = false;
			
			for($j=0; $j < count($smiles); $j++)
			{
				// 自分自身がスマイルしたかどうか
				if($smiles[$j]['user_id']==$this->Session->read('Auth.User.id'))
				{
					$is_smiled = true;
				}
				else
				{
					array_push($smiled_ids, $smiles[$j]['user_id']);
				}
			}
			
			$this->User->recursive = 0;
			$user = $this->User->find('first', array(
				'fields' => array("GROUP_CONCAT(User.name SEPARATOR ', ') as name_display"),
				'conditions'	=> array(
					'User.id'	=> $smiled_ids
				),
				'group' => array('User.id')
			));
			
			// スマイルした名前を表示
			$progresses[$i]['name_display']	= @$user[0]['name_display'];
			// 自分自身がスマイルしたかどうか
			$progresses[$i]['is_smiled']	= $is_smiled;
		}
		
		$is_add  = ($progress_id==null);
		
		if ($this->request->is(array(
				'post',
				'put'
		)))
		{
			if(Configure::read('demo_mode'))
				return;
			
			if ($progress_id == null)
			{
				$this->request->data['Progress']['user_id'] = $this->Session->read('Auth.User.id');
				$this->request->data['Progress']['task_id'] = $task_id;
			}
			
			if (! $this->Progress->validates())
				return;
			
			if ($this->Progress->save($this->request->data))
			{
				$this->__save_record($task_id, $progress_id);

				$this->Flash->success(__('進捗を保存しました'));
				return $this->redirect(array(
					'controller' => 'progresses',
					'action' => 'index',
					$task_id
				));
			}
			else
			{
				$this->Flash->error(__('The tasks progress could not be saved. Please, try again.'));
			}
		}
		else
		{
			$options = array( 'conditions' => array(
				'Progress.' . $this->Progress->primaryKey => $progress_id
			));
			
			$this->request->data = $this->Progress->find('first', $options);
		}
		
		$this->set(compact('content', 'progresses', 'is_record', 'is_admin', 'is_add', 'is_user'));
	}
	
	private function __save_record($task_id, $progress_id)
	{
		$is_add			= ($progress_id==null);
		$progress_type	= $this->request->data['Progress']['progress_type'];
		$record_type	= $is_add ? $progress_type : $progress_type.'_update';
		$task_status	= $this->request->data['Progress']['status'];
		$emotion_icon	= $this->request->data['Progress']['emotion_icon'];
		
		// 課題の進捗率を更新（種別が進捗の場合のみ）
		if($progress_type=='progress')
		{
			$this->loadModel('Task');
			$this->Task->updateRate($task_id);
		}
		
		// 課題のステータスを更新
		$this->Task->id = $task_id;
		$this->Task->saveField('status', $task_status);
		
		// 課題情報を取得
		$task = $this->Task->find('first', array(
			'conditions' => array(
				'Task.id' => $task_id
			)
		));
		
		// 学習履歴を追加
		$this->loadModel('Record');
		
		$this->Record->addRecord(array(
			'user_id'		=> $this->Session->read('Auth.User.id'),
			'theme_id'		=> $task['Theme']['id'],
			'task_id'		=> $task_id,
			'study_sec'		=> $this->request->data['study_sec'],
			'emotion_icon'	=> $emotion_icon,
			'record_type'	=> $record_type,
		));
		
		// 課題の更新日時を更新
		$this->Task->id = $task_id;
		$this->Task->saveField('modified', date(date('Y-m-d H:i:s')));
		
		// 学習テーマの更新日時を更新
		$this->Task->Theme->id = $task['Theme']['id'];
		$this->Task->Theme->saveField('modified', date(date('Y-m-d H:i:s')));
		
		if(@$this->request->data['is_mail']=='on')
		{
			$this->loadModel('UsersTheme');
			
			// 学習テーマに紐づくユーザのメールアドレスを取得
			$list = $this->UsersTheme->getMailList($this->Session->read('Auth.User.id'), $task['Theme']['id']);
			
			$admin_from	= Configure :: read('admin_from');
			$mail_title	= Configure :: read('mail_title');
			$url = Router::url(array('controller' => 'users', 'action' => 'login'), true);
			
			$params = array(
				'name'			=> $this->Session->read('Auth.User.name'),
				'theme_title'	=> $task['Theme']['title'],
				'content_title'	=> $task['Task']['title'],
				'record_type'	=> Configure::read('record_type.'.$record_type),
				'url'			=> $url,
			);
			
			// メールの送信
			foreach ($list as $item)
			{
				$mail = new CakeEmail();
				$mail->from($admin_from);
				$mail->to($item);
				$mail->subject($mail_title);
				$mail->template('update');
				$mail->viewVars($params);
				$mail->send();
			}
		}
	}

	public function index_enq($task_id, $record_id = null)
	{
		$this->Progress->recursive = 0;
		$progresses = $this->Progress->find('all', array(
			'conditions' => array(
				'task_id' => $task_id
			),
			'order' => array('Progress.sort_no' => 'asc')
		));
		
		// 管理者以外の場合、コンテンツの閲覧権限の確認
		if(
			($this->Session->read('Auth.User.role') != 'admin')&&
			($this->Session->read('Auth.User.role') != 'manager')
		)
		{
			$this->loadModel('Theme');
			
			if(count($progresses) > 0)
			{
				if(! $this->Theme->hasRight($this->Session->read('Auth.User.id'), $progresses[0]['Task']['theme_id']))
				{
					throw new NotFoundException(__('Invalid access'));
				}
			}
		}
		
		// レコードIDが指定されている場合、成績を取得
		if ($record_id)
		{
			$this->loadModel('Record');
			$record = $this->Record->find('first', array(
				'conditions' => array(
					'Record.id' => $record_id
				)
			));
			
			$this->set('mode',   "record");
			$this->set('record', $record);
		}
		
		// コンテンツ情報を取得
		$this->loadModel('Task');
		$content = $this->Task->find('first', array(
			'conditions' => array(
				'Task.id' => $task_id
			)
		));
		
		// 採点処理
		if ($this->request->is('post'))
		{
			$details = array();
			
			// 成績の詳細情報の作成
			$i = 0;
			foreach ($progresses as $progress)
			{
				$progress_id = $progress['Progress']['id'];
				$answer = @$this->request->data['answer_' . $progress_id];
				
				$details[$i] = array(
					'progress_id' => $progress_id,
					'answer' => $answer,
				);
				$i ++;
			}
			
			$record = array(
				'study_sec' => $this->request->data['Progress']['study_sec']
			);
			
			$this->loadModel('Record');
			$this->Record->create();
			
			//debug($this->Record);
			$data = array(
				'user_id'		=> $this->Session->read('Auth.User.id'),
				'theme_id'		=> 0,
				'task_id'	=> $task_id,
				'study_sec'		=> $record['study_sec'],
				'is_complete'	=> 1
			);
			
			//debug($data);
			if ($this->Record->save($data))
			{
				$this->loadModel('RecordsQuestion');
				$record_id = $this->Record->getLastInsertID();
				
				foreach ($details as $detail)
				{
					$this->RecordsQuestion->create();
					$detail['record_id'] = $record_id;
					$this->RecordsQuestion->save($detail);
				}
				
				$this->Flash->success(__('アンケートの回答内容を送信しました'));
				$this->redirect(array(
					'action' => 'record_enq',
					$task_id,
					$this->Record->getLastInsertID()
				));
			}
			else
			{
				$this->Flash->error(__('The tasks progress could not be saved. Please, try again.'));
			}
		}
		
		$is_record = (($this->action == 'record_enq') || ($this->action == 'admin_record_enq') || ($this->action == 'admin_record_enq_each'));
		$is_admin  = (($this->action == 'admin_record_enq') || ($this->action == 'admin_record_enq_each'));
		//debug($is_record);
		
		$this->set(compact('content', 'progresses', 'is_record', 'is_admin'));
	}

	public function record($id, $record_id)
	{
		$this->index($id, $record_id);
		$this->render('index');
	}

	public function record_enq($id, $record_id)
	{
		$this->index_enq($id, $record_id);
		$this->render('index_enq');
	}

	public function admin_record($id, $record_id)
	{
		$this->index($id, $record_id);
		$this->render('index');
	}

	public function admin_record_enq($id, $record_id)
	{
		$this->index_enq($id, $record_id);
		$this->render('index_enq');
	}

	public function admin_index($task_id, $progress_id = null)
	{
		$this->index($task_id, $progress_id);
		$this->render('index');
	}

	public function admin_index_enq($id)
	{
		$this->Progress->recursive = 0;
		$progresses = $this->Progress->find('all', 
				array(
						'conditions' => array(
								'task_id' => $id
						),
						'order' => array('Progress.sort_no' => 'asc')
				));
		
		// コースの情報を取得
		$this->loadModel('Task');
		
		$content = $this->Task->find('first',
				array(
						'conditions' => array(
								'Task.id' => $id
						)
				));
		
		$this->set(compact('content', 'progresses'));
	}

	public function admin_add_enq($task_id)
	{
		$this->admin_edit_enq($task_id);
		$this->render('admin_edit_enq');
	}

	public function admin_edit_enq($task_id, $id = null)
	{
		//$this->Progress->validator()->delete('option_list');
		
		//$this->Progress->validate['option_list'] = null;
		//$this->Progress->unbindValidation('remove', array('option_list'), true);
		unset($this->Progress->validate['option_list']['notBlank']);
		//debug($this->Progress->validator());
		/*
		$this->User->validator()->add('group_id', 'required', array(
			'rule' => 'notBlank',
			'required' => 'create'
		));
		*/
		if ($this->action == 'edit_enq' && ! $this->Post->exists($id))
		{
			throw new NotFoundException(__('Invalid tasks progress'));
		}
		if ($this->request->is(array(
				'post',
				'put'
		)))
		{
			if ($id == null)
			{
				$this->request->data['Progress']['user_id'] = $this->Session->read('Auth.User.id');
				$this->request->data['Progress']['task_id'] = $task_id;
			}
			
			if (! $this->Progress->validates())
				return;
			
			//debug($this->request->data);
			//exit;
			if ($this->Progress->save($this->request->data))
			{
				$this->Flash->success(__('質問が保存されました'));
				return $this->redirect(
						array(
								'controller' => 'progresses',
								'action' => 'index_enq',
								$task_id
						));
			}
			else
			{
				$this->Flash->error(__('The tasks progress could not be saved. Please, try again.'));
			}
		}
		else
		{
			$options = array(
					'conditions' => array(
							'Progress.' . $this->Progress->primaryKey => $id
					)
			);
			$this->request->data = $this->Progress->find('first', $options);
		}
		
		// コンテンツ情報を取得
		$this->loadModel('Task');
		$content = $this->Task->find('first', array(
			'conditions' => array(
				'Task.id' => $task_id
			)
		));
		
		$this->set(compact('content'));
	}

	/**
	 * delete method
	 *
	 * @throws NotFoundException
	 * @param string $id        	
	 * @return void
	 */
	public function admin_delete($id)
	{
		$this->delete($id);
	}
	
	public function delete($id)
	{
		$this->Progress->id = $id;
		if (! $this->Progress->exists())
		{
			throw new NotFoundException(__('Invalid tasks progress'));
		}
		
		$this->request->allowMethod('post', 'delete');
		
		// 問題情報を取得
		$progress = $this->Progress->find('first', array(
			'conditions' => array(
				'Progress.id' => $id
			)
		));
		
		if ($this->Progress->delete())
		{
			$this->Flash->success(__('問題が削除されました'));
			return $this->redirect(array(
				'controller' => 'progresses',
				'action' => 'index',
				$progress['Progress']['task_id']
			));
			return $this->redirect(array(
					'action' => 'index'
			));
		}
		else
		{
			$this->Flash->error(__('The tasks progress could not be deleted. Please, try again.'));
		}
	}

	public function admin_delete_enq($task_id, $id)
	{
		$this->Progress->id = $id;
		if (! $this->Progress->exists())
		{
			throw new NotFoundException(__('Invalid tasks progress'));
		}
		
		$this->request->allowMethod('post', 'delete');
		
		// 問題情報を取得
		$progress = $this->Progress->find('first', array(
			'conditions' => array(
				'Progress.id' => $id
			)
		));
		
		if ($this->Progress->delete())
		{
			$this->Flash->success(__('質問が削除されました'));
			return $this->redirect(
					array(
							'controller' => 'progresses',
							'action' => 'index_enq',
							$task_id
					));
		}
		else
		{
			$this->Flash->error(__('The tasks progress could not be deleted. Please, try again.'));
		}
	}

	public function admin_order()
	{
		$this->autoRender = FALSE;
		if($this->request->is('ajax'))
		{
			$this->Progress->setOrder($this->data['id_list']);
			return "OK";
		}
	}

	public function admin_smile()
	{
		$this->smile();
	}

	public function smile()
	{
		$this->autoRender = FALSE;
		if($this->request->is('ajax'))
		{
			$data = array(
				'progress_id'	=> $this->data['progress_id'],
				'user_id'		=> $this->Session->read('Auth.User.id'),
			);
			
			$this->loadModel('Smile');
			
			$smile = $this->Smile->find('first', array(
				'conditions' => $data
			));
			
			if($smile)
			{
				$this->Smile->delete($smile['Smile']['id']);
			}
			else
			{
				$this->Smile->create();
				$this->Smile->save($data);
			}
			
			return "OK";
		}
	}
}
