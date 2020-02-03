<?php

namespace Drupal\instagram_formatter\Plugin\Field\FieldFormatter;

use Drupal;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\smart_trim\Truncate\TruncateHTML;
use GuzzleHttp\Exception\TransferException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'instagram_block' formatter.
 *
 * @FieldFormatter(
 *   id = "instagram_block",
 *   label = @Translation("Instagram Block"),
 *   field_types = {
 *     "string"
 *   }
 * )
 */
class InstagramFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * Creates an instance of the plugin.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container to pull out services used in the plugin.
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   *
   * @return static
   *   Returns an instance of this plugin.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings']
    );
  }

  /**
   * @return array
   */
  public static function defaultSettings() {
    return [
        'num' => 4,
        'caption' => TRUE,
      ] + parent::defaultSettings();
  }

  /**
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return array
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    $elements['num'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of Posts to display'),
      '#default_value' => $this->getSetting('num'),
      '#min' => 1,
      '#description' => $this->t('Number of Posts to display.'),
    ];

    $elements['caption'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Caption'),
      '#default_value' => $this->getSetting('caption'),
      '#description' => $this->t('Disable to hide caption of Instagram post.'),
    ];

    return $elements;
  }

  /**
   * Builds a renderable array for a field value.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field values to be rendered.
   * @param string $langcode
   *   The language that should be used to render the field.
   *
   * @return array
   *   A renderable array for $items, as an array of child elements keyed by
   *   consecutive numeric indexes starting from 0.
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $build = [];

    foreach ($items as $delta => $item) {
      $build['profile'] = $item->value;
    }

    // Build a render array to return the Instagram Images.
    if (isset($build['profile'])) {

      $url = "https://www.instagram.com/{$build['profile']}";

      // Get Response file_get_contents($url);
      try {
        $instagram_url = Drupal::httpClient()->get($url);
        $response = (string) $instagram_url->getBody();
      } catch (TransferException $e) {
        return [];
      }

      // The start position.
      $start_position = strpos($response, 'window._sharedData = ');
      // String length to trim before.
      $start_positionlength = strlen('window._sharedData = ');
      // Trim preceding content.
      $trimmed_before = trim(substr($response, ($start_position + $start_positionlength)));
      // End position.
      $end_position = strpos($trimmed_before, '</script>');
      // Trim content.
      $trimmed = trim(substr($trimmed_before, 0, $end_position));
      // Remove extra trailing ";".
      $jsondata = substr($trimmed, 0, -1);
      // JSON decode.
      $obj = Json::decode($jsondata, TRUE);

      // If the profile private.
      if ($obj['entry_data']['ProfilePage']['0']['graphql']['user']['is_private']) {
        return [];
      }

      if (isset($obj['entry_data']['ProfilePage']['0']['graphql']['user']['edge_owner_to_timeline_media']['edges'])) {
        $variable = $obj['entry_data']['ProfilePage']['0']['graphql']['user']['edge_owner_to_timeline_media']['edges'];

        // Get only the image Post.
        foreach ($variable as $key => $value) {
          if (!$value['node']['is_video']) {
            $image_post[] = $value;
          }
        }

        if (count($image_post) < 4) {
          foreach ($variable as $key => $value) {
            if ($value['node']['is_video']) {
              $image_post[] = $value;
            }
          }
        }

        $slice_variable = array_slice($image_post, 0, $this->settings['num']);
        foreach ($slice_variable as $key => $value) {
          // Generate path.
          $shortcode = $value['node']['shortcode'];
          // Image source.
          $src = $value['node']['thumbnail_src'];
          if (isset($value['node']['edge_media_to_caption']['edges'][0]['node']['text'])) {
            $caption = $value['node']['edge_media_to_caption']['edges'][0]['node']['text'];
          } else {
            $caption = '';
          }
          $truncate = new TruncateHTML();
          $trim_caption = $truncate->truncateChars($caption, 100, '...');

          $data['posts'][] = [
            'image' => $src,
            'path' => 'https://www.instagram.com/p/' . $shortcode,
            'caption' => $trim_caption,
          ];
        }
        $data['profile'] = '@' . $item->value;
        $data['profile_url'] = $url;

        $build = [
          '#theme' => 'instagram_formatter_post',
          '#data' => $data,
          '#profile' => $item->value,
        ];
      }
    }

    return $build;
  }

}
