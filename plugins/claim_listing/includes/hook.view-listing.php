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

if (!empty($item) && !empty($listing))
{
	$disabledItems = array('members');

	if (in_array($item, $disabledItems))
	{
		return;
	}

	$iaItem = $iaCore->factory('item');

	// check for ownership key
	if (isset($_GET['ownership-key']))
	{
		$iaDb->setTable('claim_pending_email_keys');

		$key = $iaDb->row_bind(iaDb::ALL_COLUMNS_SELECTION, '`item` = :item AND `item_id` = :id AND `key` = :key',
			array('item' => $item, 'id' => $listing, 'key' => $_GET['ownership-key']));

		if ($key)
		{
			$tableName = $iaItem->getItemTable($item);

			$iaDb->update(array('member_id' => $key['member_id']), iaDb::convertIds($listing), null, $tableName);
			$iaDb->delete(iaDb::convertIds($key['key'], 'key'));

			$iaView->setMessages(iaLanguage::get('ownership_changed'), iaView::SUCCESS);
			iaUtil::reload();
		}

		$iaDb->resetTable();
	}

	if (!iaUsers::hasIdentity())
	{
		$iaCore->factory('item')->setItemTools(array(
			'title' => iaLanguage::get('claim_listing'),
			'url' => 'javascript:void(0);" onclick="intelli.notifFloatBox({msg:\'' . iaSanitize::html(iaLanguage::get('sign_in_to_use_this_feature')) . '\',autohide:true});return false;',
		));

		return;
	}

	$itemTable = $iaItem->getItemTable($item);
	$itemData = $iaDb->row(iaDb::ALL_COLUMNS_SELECTION, iaDb::convertIds($listing), $itemTable);

	// check the current owner of the listing, if possible
	if (isset($itemData['member_id']) && iaUsers::getIdentity()->id == $itemData['member_id'])
	{
		return;
	}
	//

	$iaCore->factory('item')->setItemTools(array(
		'title' => iaLanguage::get('claim_listing'),
		'url' => IA_URL . 'claim/' . $item . '/' . $listing . '.json" id="js-cmd-claim" data-toggle="modal" data-target="#js-claim-modal'
	));
}