<?php

/**
 * @file
 * Contains \Drupal\xmlsitemap\Form\XmlSitemapSettingsForm.
 */

namespace Drupal\xmlsitemap\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Render\Element;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\xmlsitemap\XmlSitemapLinkStorageInterface;

/**
 * Configure xmlsitemap settings for this site.
 */
class XmlSitemapSettingsForm extends ConfigFormBase {

  /**
   * The state store.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The form builder service.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * The xmlsitemap.link_storage service.
   *
   * @var \Drupal\xmlsitemap\XmlSitemapLinkInterface
   */
  protected $linkStorage;

  /**
   * Constructs a new XmlSitemapSettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Form\FormBuilderInterface $state
   *   The form builder service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, StateInterface $state, FormBuilderInterface $form_builder, XmlSitemapLinkStorageInterface $link_storage) {
    parent::__construct($config_factory);
    $this->state = $state;
    $this->formBuilder = $form_builder;
    $this->linkStorage = $link_storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
        $container->get('config.factory'), $container->get('state'), $container->get('form_builder'), $container->get('xmlsitemap.link_storage')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'xmlsitemap_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, array &$form_state) {
    $config = $this->config('xmlsitemap.settings');
    $intervals = array(300, 900, 1800, 3600, 10800, 21600, 43200, 86400, 172800, 259200, 604800);
    $form['minimum_lifetime'] = array(
      '#type' => 'select',
      '#title' => t('Minimum sitemap lifetime'),
      '#options' => array(0 => t('No minimum')) + array_map('format_interval', array_combine($intervals, $intervals)),
      '#description' => t('The minimum amount of time that will elapse before the sitemaps are regenerated. The sitemaps will also only be regenerated on cron if any links have been added, updated, or deleted.') . '<br />' . t('Recommended value: %value.', array('%value' => t('1 day'))),
      '#default_value' => $config->get('minimum_lifetime'),
    );
    $form['xsl'] = array(
      '#type' => 'checkbox',
      '#title' => t('Include a stylesheet in the sitemaps for humans.'),
      '#description' => t('When enabled, this will add formatting and tables with sorting to make it easier to view the XML sitemap data instead of viewing raw XML output. Search engines will ignore this.'),
      '#default_value' => $config->get('xsl'),
    );
    $form['prefetch_aliases'] = array(
      '#type' => 'checkbox',
      '#title' => t('Prefetch URL aliases during sitemap generation.'),
      '#description' => t('When enabled, this will fetch all URL aliases at once instead of one at a time during sitemap generation. For medium or large sites, it is recommended to disable this feature as it uses a lot of memory.'),
      '#default_value' => $config->get('prefetch_aliases'),
    );

    $form['advanced'] = array(
      '#type' => 'details',
      '#title' => t('Advanced settings'),
      '#collapsible' => TRUE,
      '#collapsed' => !$this->state->get('xmlsitemap_developer_mode'),
      '#weight' => 10,
    );
    $form['advanced']['gz'] = array(
      '#type' => 'checkbox',
      '#title' => t('Generate additional compressed sitemaps using gzip.'),
      '#default_value' => $config->get('gz'),
      '#disabled' => !function_exists('gzencode'),
    );
    $chunk_sizes = array(100, 500, 1000, 2500, 5000, 10000, 25000, XMLSITEMAP_MAX_SITEMAP_LINKS);
    $form['advanced']['chunk_size'] = array(
      '#type' => 'select',
      '#title' => t('Number of links in each sitemap page'),
      '#options' => array('auto' => t('Automatic (recommended)')) + array_combine($chunk_sizes, $chunk_sizes),
      '#default_value' => xmlsitemap_var('chunk_size'),
      // @todo This description is not clear.
      '#description' => t('If there are problems with rebuilding the sitemap, you may want to manually set this value. If you have more than @max links, an index with multiple sitemap pages will be generated. There is a maximum of @max sitemap pages.', array('@max' => XMLSITEMAP_MAX_SITEMAP_LINKS)),
    );
    $batch_limits = array(5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000);
    $form['advanced']['batch_limit'] = array(
      '#type' => 'select',
      '#title' => t('Maximum number of sitemap links to process at once'),
      '#options' => array_combine($batch_limits, $batch_limits),
      '#default_value' => xmlsitemap_var('batch_limit'),
      '#description' => t('If you have problems running cron or rebuilding the sitemap, you may want to lower this value.'),
    );
    if (!xmlsitemap_check_directory()) {
      $this->formBuilder->setErrorByName('path', $form_state, t('The directory %directory does not exist or is not writable.', array('%directory' => xmlsitemap_get_directory())));
    }
    $form['advanced']['path'] = array(
      '#type' => 'textfield',
      '#title' => t('Sitemap cache directory'),
      '#default_value' => $config->get('path'),
      '#size' => 30,
      '#maxlength' => 255,
      '#description' => t('Subdirectory where the sitemap data will be stored. This folder <strong>must not be shared</strong> with any other Drupal site or install using XML sitemap.'),
      '#field_prefix' => file_build_uri(''),
      '#required' => TRUE,
    );
    $form['advanced']['xmlsitemap_base_url'] = array(
      '#type' => 'textfield',
      '#title' => t('Default base URL'),
      '#default_value' => $this->state->get('xmlsitemap_base_url'),
      '#size' => 30,
      '#description' => t('This is the default base URL used for sitemaps and sitemap links.'),
      '#required' => TRUE,
    );
    $form['advanced']['lastmod_format'] = array(
      '#type' => 'select',
      '#title' => t('Last modification date format'),
      '#options' => array(
        XMLSITEMAP_LASTMOD_SHORT => t('Short'),
        XMLSITEMAP_LASTMOD_MEDIUM => t('Medium'),
        XMLSITEMAP_LASTMOD_LONG => t('Long'),
      ),
      '#default_value' => $config->get('lastmod_format'),
    );
    foreach ($form['advanced']['lastmod_format']['#options'] as $key => &$label) {
      $label .= ' (' . gmdate($key, REQUEST_TIME) . ')';
    }
    $form['advanced']['xmlsitemap_developer_mode'] = array(
      '#type' => 'checkbox',
      '#title' => t('Enable developer mode to expose additional settings.'),
      '#default_value' => $this->state->get('xmlsitemap_developer_mode'),
    );

    $form['xmlsitemap_settings'] = array(
      '#type' => 'vertical_tabs',
      '#weight' => 20,
    );

    $entities = xmlsitemap_get_link_info(NULL, TRUE);
    foreach ($entities as $entity => $entity_info) {
      if (!$config->get('xmlsitemap_entity_' . $entity) && $entity != 'frontpage') {
        continue;
      }
      $form[$entity] = array(
        '#type' => 'details',
        '#title' => $entity_info['label'],
        '#collapsible' => TRUE,
        '#collapsed' => TRUE,
        '#group' => 'xmlsitemap_settings',
      );

      if (!empty($entity_info['bundles'])) {
        // If this entity has bundles, show a bundle setting summary.
        xmlsitemap_add_form_entity_summary($form[$entity], $entity, $entity_info);
      }

      if (!empty($entity_info['xmlsitemap']['settings callback'])) {
        // Add any entity-specific settings.
        $entity_info['xmlsitemap']['settings callback']($form[$entity]);
      }

      // Ensure that the entity fieldset is not shown if there are no accessible
      // sub-elements.
      $form[$entity]['#access'] = (bool) Element::getVisibleChildren($form[$entity]);
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, array &$form_state) {
    // Check that the chunk size will not create more than 1000 chunks.
    $chunk_size = $form_state['values']['chunk_size'];
    if ($chunk_size != 'auto' && $chunk_size != 50000 && (xmlsitemap_get_link_count() / $chunk_size) > 1000) {
      form_set_error('chunk_size', t('The sitemap page link count of @size will create more than 1,000 sitemap pages. Please increase the link count.', array('@size' => $chunk_size)));
    }

    $base_url = &$form_state['values']['xmlsitemap_base_url'];
    $base_url = rtrim($base_url, '/');
    if ($base_url != '' && !UrlHelper::isValid($base_url, TRUE)) {
      $this->formBuilder->setErrorByName('xmlsitemap_base_url', $form_state, t('Invalid base URL.'));
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, array &$form_state) {
    // Save any changes to the frontpage link.
    $config = $this->config('xmlsitemap.settings');
    $values = $form_state['values'];
    if (isset($form['frontpage'])) {
      $frontpage_priority = $values['xmlsitemap_frontpage_priority'];
      $frontpage_changefreq = $values['xmlsitemap_frontpage_changefreq'];
      $config->set('frontpage_priority', $frontpage_priority);
      $config->set('frontpage_changefreq', $frontpage_changefreq);
      $config->save();
      $this->linkStorage->save(array('type' => 'frontpage', 'id' => 0, 'loc' => '', 'priority' => $frontpage_priority, 'changefreq' => $frontpage_changefreq));
    }
    $this->state->set('xmlsitemap_developer_mode', $values['xmlsitemap_developer_mode']);
    $this->state->set('xmlsitemap_base_url', $values['xmlsitemap_base_url']);
    unset($values['xmlsitemap_developer_mode']);
    unset($values['xmlsitemap_base_url']);
    foreach ($values as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();

    parent::submitForm($form, $form_state);
  }

}
