<?php
/**
 * @package    System - JLSitemap Cron Plugin
 * @version    @version@
 * @author     Joomline - joomline.ru
 * @copyright  Copyright (c) 2010 - 2022 Joomline. All rights reserved.
 * @license    GNU/GPL license: http://www.gnu.org/copyleft/gpl.html
 * @link       https://joomline.ru/
 */

defined('_JEXEC') or die;

use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

class PlgSystemJLSitemap_Cron extends CMSPlugin
{
	/**
	 * Affects constructor behavior. If true, language files will be loaded automatically.
	 *
	 * @var  boolean
	 *
	 * @since  0.0.2
	 */
	protected $autoloadLanguage = true;

	/**
	 * Cron last run date.
	 *
	 * @var  string
	 *
	 * @since  1.10.1
	 */
	protected $_lastRun = null;

	/**
	 * Method to add cron js.
	 *
	 * @throws  Exception
	 *
	 * @since  0.0.2
	 */
	public function onBeforeRender()
	{
		if ($this->params->get('client_enable') && $this->checkCacheTime())
		{
			$app  = Factory::getApplication();
			$mode = $this->params->get('client_mode', 'all');
			if ($mode == 'all' || ($mode == 'admin' && $app->isClient('administrator')) || ($mode == 'site' && $app->isClient('site')))
			{
				// Set params
				$site   = SiteApplication::getInstance('site');
				$router = $site->getRouter();
				$link   = 'index.php?option=com_ajax&plugin=jlsitemap_cron&group=system&format=json';
				$link   = str_replace('administrator/', '', $router->build($link)->toString());
				$link   = str_replace('/?', '?', $link);
				$link   = trim(Uri::root(true), '/') . '/' . trim($link, '/');

				$params = array('ajax_url' => $link);
				Factory::getDocument()->addScriptOptions('jlsitemap_cron', $params);

				// Add script
				HTMLHelper::_('script', 'media/plg_system_jlsitemap_cron/js/cron.min.js', array('version' => 'auto'));
			}
		}
	}

	/**
	 * Method to run cron
	 *
	 * @throws  Exception
	 *
	 * @return  bool True on success, False on failure.
	 *
	 * @since  0.0.2
	 */
	public function onAjaxJlsitemap_cron()
	{
		$app       = Factory::getApplication();
		$generate  = false;
		$error     = '';
		$clientRun = $this->params->get('client_enable');

		// Client checks
		if ($clientRun)
		{		
			if ($this->checkCacheTime())
			{
				$generate = true;
			}
			else
			{
				$error = Text::_('PLG_SYSTEM_JLSITEMAP_GENERATION_ERROR_CACHE');
			}
		}

		// Server checks
		if (!$clientRun)
		{
			if (!$this->params->get('key_enabled'))
			{
				$generate = true;
			}
			elseif (!$generate = ($app->input->get('key', '') == $this->params->get('key')))
			{
				$error = Text::_('PLG_SYSTEM_JLSITEMAP_GENERATION_ERROR_KEY');
			}
		}

		// Run generation
		if (!$error && $generate && $urls = $this->generate())
		{
			$success = Text::sprintf('PLG_SYSTEM_JLSITEMAP_GENERATION_SUCCESS', count($urls->includes),
				count($urls->excludes), count($urls->all));

			//  Prepare json response
			if ($app->input->get('format', 'raw') == 'json')
			{
				$success = explode('<br>', $success);
			}

			return $success;
		}
		elseif ($error)
		{
			throw new Exception(Text::sprintf('PLG_SYSTEM_JLSITEMAP_GENERATION_FAILURE', $error));
		}

		return false;
	}

	/**
	 * Method to generate site map.
	 *
	 * @throws  Exception
	 *
	 * @return  object|false Sitemap generation result on success, False on failure.
	 *
	 * @since  0.0.2
	 */
	protected function generate()
	{
		try
		{
			// Update last run
			$this->params->set('last_run', Factory::getDate()->toSql());
			$plugin          = new stdClass();
			$plugin->type    = 'plugin';
			$plugin->element = 'jlsitemap_cron';
			$plugin->folder  = 'system';
			$plugin->params  = (string) $this->params;
			Factory::getDbo()->updateObject('#__extensions', $plugin, array('type', 'element', 'folder'));

			// Run generation
			/* @var  $model  JLSitemapModelSitemap */
			BaseDatabaseModel::addIncludePath(JPATH_SITE . '/components/com_jlsitemap/models');
			$model = BaseDatabaseModel::getInstance('Sitemap', 'JLSitemapModel', array('ignore_request' => true));

			if (!$result = $model->generate())
			{
				throw new Exception(Text::sprintf('PLG_SYSTEM_JLSITEMAP_GENERATION_FAILURE', $model->getError()));
			}

			return $result;
		}
		catch (Exception $e)
		{
			throw new Exception(Text::sprintf('PLG_SYSTEM_JLSITEMAP_GENERATION_FAILURE', $e->getMessage()));
		}
	}

	/**
	 * Method to check client cache time
	 *
	 * @return  bool True if  run. False if don't  run
	 *
	 * @since  0.0.2
	 */
	protected function checkCacheTime()
	{
		if ($this->_lastRun === null)
		{
			$db             = Factory::getDbo();
			$query          = $db->getQuery('true')
				->select('params')
				->from('#__extensions')
				->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
				->where($db->quoteName('element') . ' = ' . $db->quote('jlsitemap_cron'))
				->where($db->quoteName('folder') . ' = ' . $db->quote('system'));
			$params         = new Registry($db->setQuery($query)->loadResult());
			$this->_lastRun = $params->get('last_run', false);
		}

		if (!$this->_lastRun)
		{
			return true;
		}

		// Prepare cache time
		$offset = ' + ' . $this->params->get('client_cache_number', 1) . ' ' .
			$this->params->get('client_cache_value', 'day');
		$cache  = new Date($this->_lastRun . $offset);

		return (Factory::getDate()->toUnix() >= $cache->toUnix());
	}
}
