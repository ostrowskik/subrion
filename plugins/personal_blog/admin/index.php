<?php
/******************************************************************************
 *
 * Subrion - open source content management system
 * Copyright (C) 2015 Intelliants, LLC <http://www.intelliants.com>
 *
 * This file is part of Subrion.
 *
 * Subrion is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Subrion is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Subrion. If not, see <http://www.gnu.org/licenses/>.
 *
 *
 * @link http://www.subrion.org/
 *
 ******************************************************************************/

class iaBackendController extends iaAbstractControllerPluginBackend
{
	protected $_name = 'blog';
	protected $_table = 'blog_entries';
	protected $_tableBlogTags = 'blog_tags';
	protected $_tableBlogEntriesTags = 'blog_entries_tags';

	protected $_pluginName = 'personal_blog';

	protected $_gridFilters = array('status' => 'equal');
	protected $_gridQueryMainTableAlias = 'b';

	protected $_phraseAddSuccess = 'blog_entry_added';
	protected $_phraseGridEntryDeleted = 'blog_entry_deleted';


	public function __construct()
	{
		parent::__construct();

		$iaBlog = $this->_iaCore->factoryPlugin($this->getPluginName(), iaCore::ADMIN, $this->getName());
		$this->setHelper($iaBlog);
	}

	protected function _indexPage(&$iaView)
	{
		$iaView->grid('_IA_URL_plugins/' . $this->getPluginName() . '/js/admin/index');
	}

	protected function _modifyGridParams(&$conditions, &$values, array $params)
	{
		if (!empty($_GET['text']))
		{
			$conditions[] = '(`title` LIKE :text OR `body` LIKE :text)';
			$values['text'] = '%' . iaSanitize::sql($_GET['text']) . '%';
		}

		if (!empty($params['owner']))
		{
			$conditions[] = '(m.`fullname` LIKE :owner OR m.`username` LIKE :owner)';
			$values['owner'] = '%' . iaSanitize::sql($params['owner']) . '%';
		}
	}

	protected function _gridRead($params)
	{
		return (isset($params['get']) && 'alias' == $params['get'])
			? array('url' => IA_URL . 'blog' . IA_URL_DELIMITER . $this->_iaDb->getNextId() . '-' . $this->getHelper()->titleAlias($params['title']))
			: parent::_gridRead($params);
	}

	protected function _setPageTitle(&$iaView)
	{
		if (in_array($iaView->get('action'), array(iaCore::ACTION_ADD, iaCore::ACTION_EDIT)))
		{
			$iaView->title(iaLanguage::get($iaView->get('action') . '_blog_entry'));
		}
	}

	protected function _setDefaultValues(array &$entry)
	{
		$entry['title'] = $entry['body'] = $entry['tags'] = '';
		$entry['lang'] = $this->_iaCore->iaView->language;
		$entry['date_added'] = date(iaDb::DATETIME_FORMAT);
		$entry['status'] = iaCore::STATUS_ACTIVE;
		$entry['member_id'] = iaUsers::getIdentity()->id;
	}

	protected function _entryDelete($entryId)
	{
		return (bool)$this->getHelper()->delete($entryId);
	}

	protected function _preSaveEntry(array &$entry, array $data, $action)
	{
		parent::_preSaveEntry($entry, $data, $action);

		iaUtil::loadUTF8Functions('ascii', 'validation', 'bad', 'utf8_to_ascii');

		if (!utf8_is_valid($entry['title']))
		{
			$entry['title'] = utf8_bad_replace($entry['title']);
		}
		if (empty($entry['title']))
		{
			$this->addMessage('title_is_empty');
		}

		if (!utf8_is_valid($entry['body']))
		{
			$entry['body'] = utf8_bad_replace($entry['body']);
		}
		if (empty($entry['body']))
		{
			$this->addMessage('body_is_empty');
		}

		if (empty($entry['date_added']))
		{
			$entry['date_added'] = date(iaDb::DATETIME_FORMAT);
		}

		$entry['alias'] = $this->getHelper()->titleAlias(empty($entry['alias']) ? $entry['title'] : $entry['alias']);


		if (!empty($data['owner']))
		{
			if ($memberId = $this->_iaCore->iaDb->one_bind('id', '`username` = :name OR `fullname` = :name', array('name' => iaSanitize::sql($_POST['owner'])), iaUsers::getTable()))
			{
				$entry['member_id'] = $memberId;
			}
			else
			{
				$this->addMessage('incorrect_owner_specified');
			}
		}
		else
		{
			$entry['member_id'] = iaUsers::getIdentity()->id;
		}

		if ($this->getMessages())
		{
			return false;
		}

		unset($entry['owner']);

		if (isset($_FILES['image']['tmp_name']) && $_FILES['image']['tmp_name'])
		{
			$iaPicture = $this->_iaCore->factory('picture');

			$path = iaUtil::getAccountDir();
			$file = $_FILES['image'];
			$token = iaUtil::generateToken();
			$info = array(
				'image_width' => 1000,
				'image_height' => 750,
				'thumb_width' => 250,
				'thumb_height' => 250,
				'resize_mode' => iaPicture::CROP
			);

			if ($image = $iaPicture->processImage($file, $path, $token, $info))
			{
				if ($entry['image']) // it has an already assigned image
				{
					$iaPicture = $this->_iaCore->factory('picture');
					$iaPicture->delete($entry['image']);
				}

				$entry['image'] = $image;
			}
		}
		unset($entry['tags']);

		return true;
	}

	protected function _postSaveEntry(array &$entry, array $data, $action)
	{

		$tags = array_filter(explode(',', $data['tags']));

		$iaLog = $this->_iaCore->factory('log');

		$actionCode = (iaCore::ACTION_ADD == $action)
			? iaLog::ACTION_CREATE
			: iaLog::ACTION_UPDATE;
		$params = array(
			'module' => 'blog',
			'item' => 'blog',
			'name' => $entry['title'],
			'id' => $this->getEntryId()
		);

		$prefix = $this->_iaDb->prefix;
		$this->_iaDb->setTable($this->_tableBlogEntriesTags);

		$sql =
			'DELETE ' .
			'FROM `:prefix:table_blog_tags` ' .
			'WHERE `id` IN (' .
				'SELECT DISTINCT `tag_id` ' .
				'FROM `:prefix:table_blog_entries_tags` ' .
				'WHERE `tag_id` IN (' .
					'SELECT DISTINCT `tag_id` FROM `:prefix:table_blog_entries_tags` ' .
					'WHERE :id) ' .
				'GROUP BY 1 ' .
				'HAVING COUNT(*) = 1)';

		$sql = iaDb::printf($sql, array(
			'prefix' => $this->_iaDb->prefix,
			'table_blog_tags' => $this->_tableBlogTags,
			'table_blog_entries_tags' => $this->_tableBlogEntriesTags,
			'id' => iaDb::convertIds($this->getEntryId(), 'blog_id')
		));

		$this->_iaDb->query($sql);

		$sql = "DELETE FROM `{$prefix}blog_entries_tags` WHERE " . iaDb::convertIds($this->getEntryId(), 'blog_id');

		$this->_iaDb->query($sql);

		$allTagTitles = $this->_iaDb->keyvalue(array('title','id'), '',$this->_tableBlogTags);

		foreach ($tags as $tag)
		{
			$tagAlias = iaSanitize::alias(strtolower($tag));
			$tagEntry = array(
				'title' => $tag,
				'alias' => $tagAlias
			);
			$tagId = isset($allTagTitles[$tag])
				? $allTagTitles[$tag]
				: $this->_iaDb->insert($tagEntry, null, $this->_tableBlogTags);

			$tagBlogIds = array(
				'blog_id' => $this->getEntryId(),
				'tag_id' => $tagId
			);

			$this->_iaDb->insert($tagBlogIds);
		}

		$iaLog->write($actionCode, $params);
	}

	protected function _gridQuery($columns, $where, $order, $start, $limit)
	{
		$sql =
			'SELECT SQL_CALC_FOUND_ROWS ' .
			'b.`id`, b.`title`, b.`alias`, b.`date_added`, b.`status`, m.`fullname` `owner`, 1 `update`, 1 `delete` ' .
			'FROM `:prefix:table_blog_entries` b ' .
			'LEFT JOIN `:prefix:table_members` m ON (b.`member_id` = m.`id`) ' .
			($where ? "WHERE " . $where : '') . $order . ' ' .
			'LIMIT :start, :limit';

		$sql = iaDb::printf($sql, array(
			'prefix' => $this->_iaDb->prefix,
			'table_blog_entries' => $this->getTable(),
			'table_members' => iaUsers::getTable(),
			'start' => $start,
			'limit' => $limit
		));

		return $this->_iaDb->getAll($sql);
	}

	protected function _assignValues(&$iaView, array &$entryData)
	{
		$iaUsers = $this->_iaCore->factory('users');
		$owner = empty($entryData['member_id']) ? iaUsers::getIdentity(true) : $iaUsers->getInfo($entryData['member_id']);

		$entryData['owner'] = $owner['fullname'];

//		commented for cases when SET SESSION group_concat_max_len doesn't work
//		$tagIds = $this->_iaDb->all('tag_id', "`blog_id` = {$this->getEntryId()}",0, null, $this->_tableBlogEntriesTags);
//		$entryData['tags'] = '';
//		foreach ($tagIds as $tagId)
//		{
//			$tags = $this->_iaDb->all('title', "`id` = {$tagId['tag_id']}",0, null, $this->_tableBlogTags);
//			$entryData['tags'] .= $tags[0]['title'] . ',';
//		}
//		$entryData['tags'] = rtrim($entryData['tags'], ',');

		$this->_iaDb->query("SET SESSION group_concat_max_len = 2000");
		if ($this->getEntryId())
		{
			$sql =
				'SELECT GROUP_CONCAT(`title`) ' .
				'FROM `:prefix:table_blog_tags` bt ' .
				'WHERE `id` IN (' .
					'SELECT `tag_id` ' .
					'FROM `:prefix:table_blog_entries_tags` ' .
					'WHERE `blog_id` = :id)';

			$sql = iaDb::printf($sql, array(
				'prefix' => $this->_iaDb->prefix,
				'table_blog_tags' => 'blog_tags',
				'table_blog_entries_tags' => 'blog_entries_tags',
				'id' => $this->getEntryId()
			));

			$entryData['tags'] = $this->_iaDb->getOne($sql);
		}
		else if (isset($_POST['tags']))
		{
			$entryData['tags'] = iaSanitize::sql($_POST['tags']);
		}
	}

}
