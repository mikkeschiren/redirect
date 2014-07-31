<?php

/**
 * @file
 * Contains \Drupal\redirect\Form\RedirectFormController
 */

namespace Drupal\redirect\Form;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Language\Language;
use Drupal\Core\Routing\MatchingRouteNotFoundException;
use Drupal\Core\Url;
use Drupal\redirect\Entity\Redirect;
use Drupal\Core\Form\FormStateInterface;

class RedirectForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  protected function prepareEntity() {
    /** @var \Drupal\redirect\Entity\Redirect $redirect */
    $redirect = $this->entity;

    if ($redirect->isNew()) {

      // To pass in the query set parameters into GET as follows:
      // source_options[query][key1]=value1&source_options[query][key2]=value2
      $source_options = array();
      if ($this->getRequest()->get('source_options')) {
        $source_options = $this->getRequest()->get('source_options');
      }

      $redirect_options = array();
      if ($this->getRequest()->get('redirect_options')) {
        $redirect_options = $this->getRequest()->get('redirect_options');
      }

      $source_url = urldecode($this->getRequest()->get('source'));
      if (!empty($source_url)) {
        $redirect->setSource($source_url, $source_options);
      }

      $redirect_url = urldecode($this->getRequest()->get('redirect'));
      if (!empty($redirect_url)) {
        try {
          $redirect->setRedirect($redirect_url, $redirect_options);
        }
        catch (MatchingRouteNotFoundException $e) {
          drupal_set_message(t('Invalid redirect URL %url provided.', array('%url' => $redirect_url)), 'warning');
        }
      }

      $redirect->setLanguage($this->getRequest()->get('language') ? $this->getRequest()->get('language') : Language::LANGCODE_NOT_SPECIFIED);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\redirect\Entity\Redirect $redirect */
    $redirect = $this->entity;

    if (\Drupal::moduleHandler()->moduleExists('locale')) {
      $form['language'] = array(
        '#type' => 'language_select',
        '#title' => t('Language'),
        '#languages' => Language::STATE_ALL,
        '#default_value' => $form['language']['#value'],
        '#description' => t('A redirect set for a specific language will always be used when requesting this page in that language, and takes precedence over redirects set for <em>All languages</em>.'),
      );
    }
    else {
      $form['language'] = array(
        '#type' => 'value',
        '#value' => Language::LANGCODE_NOT_SPECIFIED,
      );
    }

    $default_code = $redirect->getStatusCode() ? $redirect->getStatusCode() : \Drupal::config('redirect.settings')->get('default_status_code');

    $form['status_code'] = array(
      '#type' => 'select',
      '#title' => t('Redirect status'),
      '#description' => t('You can find more information about HTTP redirect status codes at <a href="@status-codes">@status-codes</a>.', array('@status-codes' => 'http://en.wikipedia.org/wiki/List_of_HTTP_status_codes#3xx_Redirection')),
      '#default_value' => $default_code,
      '#options' => redirect_status_code_options(),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validate(array $form, FormStateInterface $form_state) {
    parent::validate($form, $form_state);
    $source = $form_state['values']['redirect_source'][0];
    $redirect = $form_state['values']['redirect_redirect'][0];

    if ($source['url'] == '<front>') {
      $this->setFormError('redirect_source', $form_state, t('It is not allowed to create a redirect from the front page.'));
    }
    if (strpos($source['url'], '#') !== FALSE) {
      $this->setFormError('redirect_source', $form_state, t('The anchor fragments are not allowed.'));
    }

    try {
      $source_url = Url::createFromPath($source['url']);
      $redirect_url = Url::createFromPath($redirect['url']);

      // It is relevant to do this comparison only in case the source path has
      // a valid route. Otherwise the validation will fail on the redirect path
      // being an invalid route.
      if ($source_url->toString() == $redirect_url->toString()) {
        $this->setFormError('redirect_redirect', $form_state, t('You are attempting to redirect the page to itself. This will result in an infinite loop.'));
      }
    }
    catch (MatchingRouteNotFoundException $e) {
      // Do nothing, we want to only compare the resulting URLs.
    }

    $parsed_url = UrlHelper::parse(trim($source['url']));
    $path = isset($parsed_url['path']) ? $parsed_url['path'] : NULL;
    $query = isset($parsed_url['query']) ? $parsed_url['query'] : NULL;
    $hash = Redirect::generateHash($path, $query, $form_state['values']['language']);

    // Search for duplicate.
    $redirects = \Drupal::entityManager()
      ->getStorage('redirect')
      ->loadByProperties(array('hash' => $hash));

    if (!empty($redirects)) {
      $redirect = array_shift($redirects);
      if ($this->entity->isNew() || $redirect->id() != $this->entity->id()) {
        $this->setFormError('redirect_source', $form_state, t('The source path %source is already being redirected. Do you want to <a href="@edit-page">edit the existing redirect</a>?',
          array(
            '%source' => $redirect->getSourceUrl(),
            '@edit-page' => url('admin/config/search/redirect/edit/' . $redirect->id()))));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $this->entity->save();
    drupal_set_message(t('The redirect has been saved.'));
    $form_state['redirect_route']['route_name'] = 'redirect.list';
  }
}
