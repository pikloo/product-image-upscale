<?php

/**
 * @since 1.0.0
 * @package ingenius_upscale_product_image
 * 
 * Plugin Name: Product Image Upscale
 * Requires Plugins: woocommerce
 * Plugin URI: https://www.example.com
 * Description: Upscale les photos des fiches produits WooCommerce
 * Version: 1.0
 * Domain Path: /languages
 * WC requires at least: 5.0
 * Requires at least: 5.5
 * Requires PHP: 7.3
 * Author: Pierre-Yves LOUKAKOU
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: iu-plugin
 */

namespace iu_plugin;

defined('ABSPATH') || exit;

if (!class_exists('IngeniusUpscalePlugin')) {
    class IngeniusUpscalePlugin
    {
        protected const WC_MIN_VERSION = '5.0.0';
        protected const PLUGIN_NAME = 'Product Image Upscale';
        protected const PLUGIN_SLUG = 'product-upscale-options';
        protected const PLUGIN_GENERAL_SETTINGS_NAME = 'Paramètres généraux';
        protected const PLUGIN_ALL_UPSCALE_NAME = 'Redimensionner toutes les images produits';
        protected const PLUGIN_ALL_UPSCALE_SLUG = 'product-upscale-all-images';
        protected const TEXT_DOMAIN = 'iu-plugin';
        protected const CLAID_API_URL = 'https://api.claid.ai';
        protected const CLAID_API_EDIT_ENDOINT = 'v1-beta1/image/edit';
        protected const DEFAULT_RESIZE_WIDTH = 2000;
        protected const DEFAULT_RESIZE_FILETYPE = 'jpeg';
        protected const IMAGE_MAX_SIZE = 2097152;
        protected const SUPPORTED_IMAGE_FORMATS = ['image/jpeg'];
        protected const CLAID_FILETYPE_META_KEY = 'iu_claid_file_type';
        protected const CLAID_SMALLER_IMAGES_META_KEY = 'iu_claid_smaller_images';
        protected const CLAID_WIDTH_META_KEY = 'iu_claid_width';
        protected const CLAID_BEARER_META_KEY = 'iu_claid_bearer';
        protected $error = false;
        protected $general_settings;
        protected $all_upscale_settings;

        function __construct()
        {
            add_action('init', array(&$this, 'iu_init'));

            //Plugin activation and deactivation
            register_activation_hook(__FILE__, array(&$this, 'iu_install'));
            register_deactivation_hook(__FILE__, array(&$this, 'iu_uninstall'));

            //Wordpress hooks
            add_action('wp_enqueue_scripts', array(&$this, 'iu_wp_enqueue_scripts'));
            add_action('admin_enqueue_scripts', array(&$this, 'iu_wp_admin_enqueue_scripts'));

            add_filter('manage_media_columns', array(&$this, 'iu_media_list_column'));
            add_action('manage_media_custom_column', array(&$this, 'iu_media_list_column_action'), 10, 2);

            add_action('wp_ajax_iu_replace_attachment', array(&$this, 'iu_replace_attachment'));
            add_action('wp_ajax_iu_get_all_products_images', array(&$this, 'iu_get_all_products_images'));
        }

        /**
         * Initialisation du plugin
         * Vérifie que les fichiers de classes requis pour le fonctionnement du plugin sont présents
         * Crée la page Menu et ses settings
         *
         * @return void
         */
        function iu_init()
        {
            add_action('admin_notices', array(&$this, 'iu_error_notice'), 10, 1);

            $classes = array(
                'Setting.php',
                'MenuPage.php',
                'ProductAttachment.php',
            );
            foreach ($classes as $file) {
                if (!file_exists(plugin_dir_path(__FILE__) . "includes/" . $file)) {
                    $this->iu_error_notice(sprintf(__('Le fichier <b>%s</b> est manquant', self::TEXT_DOMAIN), $file));
                    $this->error = true;
                } else {
                    include_once plugin_dir_path(__FILE__) . "includes/" . $file;
                }
            }

            if (!$this->error) {
                $menu_page = $this->iu_create_menu_page();
                $this->iu_create_settings($menu_page);
            }
        }


        function iu_install()
        {
        }

        /**
         * Actions à réaliser à la désactivation du plugin
         *
         * @return void
         */
        function iu_uninstall()
        {
            // Désinstallation des données persistantes
            delete_option(self::CLAID_BEARER_META_KEY);
            delete_option(self::CLAID_FILETYPE_META_KEY);
            delete_option(self::CLAID_SMALLER_IMAGES_META_KEY);
            delete_option(self::CLAID_WIDTH_META_KEY);
        }

        /**
         * Création de la page menu et de ses sous menus
         *
         * @return MenuPage
         */
        protected function iu_create_menu_page(): MenuPage
        {
            $menu_page = new MenuPage(
                __(self::PLUGIN_NAME, self::TEXT_DOMAIN),
                __(self::PLUGIN_NAME, self::TEXT_DOMAIN),
                'read',
                __(self::PLUGIN_SLUG, self::TEXT_DOMAIN),
                array(&$this, 'iu_options_page_display'),
                'dashicons-fullscreen-alt'
            );

            $general_settings_page = [
                'label' =>  __(self::PLUGIN_GENERAL_SETTINGS_NAME, self::TEXT_DOMAIN),
            ];

            $all_upscale_page = [
                'label' =>  __(self::PLUGIN_ALL_UPSCALE_NAME, self::TEXT_DOMAIN),
                'callback' => array(&$this, 'iu_options_page_all_upscale_display'),
                'menu_slug' => __(self::PLUGIN_ALL_UPSCALE_SLUG, self::TEXT_DOMAIN),
            ];

            $menu_page->iu_add_sub_menu_page($general_settings_page);
            $menu_page->iu_add_sub_menu_page($all_upscale_page);
            $menu_page->iu_menu_page_run();


            return $menu_page;
        }

        /**
         * Créations des paramètres de la page Menu et rattachements de ceux-ci aux différentes sections
         *
         * @param MenuPage $menu_page
         * @return void
         */
        protected function iu_create_settings(MenuPage $menu_page)
        {
            // Général settings
            $settings = new Setting($menu_page->iu_get_menu_slug(), array(&$this, 'iu_verify_claid_token'));
            $settings->add_section('iu_settings');
            $settings->add_field([
                'label' => __('Clé API Claid AI', self::TEXT_DOMAIN),
                'description' => __('Clé qui sera utilisée lors de l’utilisation des services de Claid AI. Reportez-vous à la <a href="' . esc_url('https://docs.claid.ai/authentication') . '" target="_blank">documentation relative à l’intégration de Claid AI</a> pour découvrir comment en créer une.', self::TEXT_DOMAIN),
                'id' => self::CLAID_BEARER_META_KEY,
                'type' => 'text',
                'placeholder' => '1/mZ1edKKACtPAb7zGlwSzvs72PvhAbGmB8K1ZrGxpcNM',
                'sanitizer' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
            ]);
            $settings->add_field([
                'label' => __('Largeur (en pixels)', self::TEXT_DOMAIN),
                'id' => self::CLAID_WIDTH_META_KEY,
                'description' => __('Par défaut: ' . self::DEFAULT_RESIZE_WIDTH . ' px', self::TEXT_DOMAIN),
                'type' => 'text',
                'placeholder' => '1000',
                'sanitizer' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
            ]);

            $settings->add_field([
                'label' => __('Type de fichier (sortie)', self::TEXT_DOMAIN),
                'id' => self::CLAID_FILETYPE_META_KEY,
                'type' => 'select',
                'options' => array(
                    0 => __('Sélectionner un type de fichier', self::TEXT_DOMAIN),
                    'jpeg' => 'jpeg',
                    'png' => 'png',
                    'webp' => 'webp',
                    'avif' => 'avif',
                ),
                'sanitizer' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
            ]);

            $settings->add_field([
                'label' => __('Inclure les images ayant une taille inférieure (hauteur ou largeur) à la taille de redimensionnement définie', self::TEXT_DOMAIN),
                'id' => self::CLAID_SMALLER_IMAGES_META_KEY,
                'description' => __('<span class="iu-desc-warning"><i class="fa-solid fa-triangle-exclamation"></i> Non recommandé : Les images pourraient être de faible qualité.</span> <br >Actuellement: ' . get_option(self::CLAID_WIDTH_META_KEY, self::DEFAULT_RESIZE_WIDTH) . ' px', self::TEXT_DOMAIN),
                'type' => 'checkbox',
            ]);

            $settings->save();
            $this->general_settings = $settings;

            // Redimensionnement settings
            $upscale_settings = new Setting(self::PLUGIN_ALL_UPSCALE_SLUG);
            $upscale_settings->add_section('iu_all_upscale_settings');
            $upscale_settings->save();
            $this->all_upscale_settings = $upscale_settings;
        }

        /**
         * Vérification du token claid via appel en cURL
         *
         * @return void
         */
        function iu_verify_claid_token()
        {
            if (null !== get_option(self::CLAID_BEARER_META_KEY) && !empty(get_option(self::CLAID_BEARER_META_KEY))) {
                $curl = curl_init();

                curl_setopt_array($curl, array(
                    CURLOPT_URL => self::CLAID_API_URL,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'GET',
                    CURLOPT_HTTPHEADER => array(
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . get_option(self::CLAID_BEARER_META_KEY)
                    ),
                ));

                $response = curl_exec($curl);
                curl_close($curl);
                $data = json_decode($response);
                if ($data->error_code == 2005) {
                    add_action('admin_notices', array(&$this, 'iu_error_notice'), 10, 1);
                    $this->iu_error_notice(__('La clé de licence Claid n’est pas valide. Si vous avez récemment créé cette clé, vous devrez peut-être attendre qu’elle soit active.', self::TEXT_DOMAIN));
                }
            }
        }

        /**
         * Affichage des messages d'erreurs
         *
         * @param string $message
         * @return void
         */
        function iu_error_notice($message = '')
        {
            if (trim($message) != '') :
?>
                <div class="error notice is-dismissible">
                    <p><b><?= self::PLUGIN_NAME ?>: </b><?= $message ?></p>
                </div>
            <?php
            endif;
        }

        /**
         * Affichage des messages de succès
         *
         * @param string $message
         * @return void
         */
        function iu_success_notice($message = '')
        {
            if (trim($message) != '') :
            ?>
                <div class="success notice">
                    <p><b><?= self::PLUGIN_NAME ?>: </b><?= $message ?></p>
                </div>
            <?php
            endif;
        }

        /**
         * Affichage de la page menu (Paramètres Généraux)
         *
         * @return void
         */
        function iu_options_page_display()
        {

            ?>
            <h1><?= __(self::PLUGIN_NAME, self::TEXT_DOMAIN) ?></h1>
            <h2><?= __(self::PLUGIN_GENERAL_SETTINGS_NAME, self::TEXT_DOMAIN) ?></h2>
        <?php
            $this->general_settings->show_form();
        }

        /**
         * Affichage de la page menu (Redimensionner toutes les images)
         *
         * @return void
         */
        function iu_options_page_all_upscale_display()
        {

            $attachments = ProductAttachment::iu_get_all_attachments();
            $total_attachments_count = count($attachments);

            if (!get_option(self::CLAID_SMALLER_IMAGES_META_KEY)) {
                $attachments = $this->iu_get_products_attachments_by_size_limit($attachments);
            }

            $count_images_to_rescale = 0;
            foreach ($attachments as $attachment) {
                $image_width = ProductAttachment::iu_get_attachment_width($attachment['ID']);
                if ($image_width != get_option(self::CLAID_WIDTH_META_KEY, self::DEFAULT_RESIZE_WIDTH)) {
                    $count_images_to_rescale++;
                }
            }

        ?>
            <h1><?= __(self::PLUGIN_NAME, self::TEXT_DOMAIN) ?></h1>
            <h2><?= __(self::PLUGIN_ALL_UPSCALE_NAME, self::TEXT_DOMAIN) ?></h2>
            <p class="notice notice-warning iu-notice--inline"><i class="fa-solid fa-triangle-exclamation"></i> Attention ! Cette opération est irréversible ! </p>
            <p>Nombre d'images produits total : <?= $total_attachments_count ?></p>
            <p data-elements-to-treat-nb="<?= $count_images_to_rescale ?>">Nombre d'images produits à redimensionner : <span id="iu-upscale-all-to-rescale-count"><?= $count_images_to_rescale ?></span></p>
        <?php

            $data = [
                'buttonText' => __('Redimensionner', self::TEXT_DOMAIN),
                'attributes' => [
                    'data-type-submit' => 'upscale',
                ]
            ];
            if ($count_images_to_rescale === 0) $data['attributes']['disabled'] = true;
            $this->all_upscale_settings->show_form($data);
        }


        /**
         * Chargement des scripts admin du plugin
         *
         * @return void
         */
        function iu_wp_admin_enqueue_scripts()
        {
            wp_enqueue_style('pluginStyle', plugins_url('css/style.css', __FILE__));
            wp_enqueue_style('fontAwesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css');
            wp_enqueue_script('jquery');
            wp_register_script('iu', plugins_url('js/iu_plugin.js', __FILE__));
            wp_enqueue_script('iu');
            wp_localize_script('iu', 'iu_data', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'api_url' => self::CLAID_API_URL . '/' . self::CLAID_API_EDIT_ENDOINT,
                'nonce' => wp_create_nonce('iu_upscale_nonce'),
                'bearer' => get_option(self::CLAID_BEARER_META_KEY, ''),
                'width' => get_option(self::CLAID_WIDTH_META_KEY, self::DEFAULT_RESIZE_WIDTH),
                'output_filetype' => get_option(self::CLAID_FILETYPE_META_KEY, self::DEFAULT_RESIZE_FILETYPE),
                'error_message' => __('Une erreur est survenue lors du redimensionnement de l\'image. Veuillez vérifier votre clé API Claid AI et votre configuration.', self::TEXT_DOMAIN),
                'loading_message' => __('En cours...', self::TEXT_DOMAIN),
            ));
        }


        /**
         * Remplacement d'une image d'un attachment (AJAX)
         *
         * @return void
         */
        function iu_replace_attachment()
        {
            $permission = check_ajax_referer('iu_upscale_nonce', 'security', false);
            if ($permission == false) {
                add_action('admin_notices', array(&$this, 'iu_error_notice'), 10, 1);
                $this->iu_error_notice(__('Permission non accordée.', self::TEXT_DOMAIN));
            } else {

                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                require_once(ABSPATH . 'wp-admin/includes/image.php');

                //Récupération de l'id de l'attachement à mettre à jour
                $image_source = $_REQUEST['imageSource'];

                $attachment_id = ProductAttachment::iu_get_attachment_id_from_url($image_source);

                //Récupération de l'URL de la nouvelle image
                $url = $_REQUEST['newImage'];
                // $url = 'https://encrage.photo/wp-content/uploads/2024/05/Tales_from_ukraine1-.jpg'; //URL TEST

                $image_data = $this->iu_get_remote_file_data($url);

                $image_mime = $image_data['type'];

                if ($image_data['filesize'] < self::IMAGE_MAX_SIZE && in_array($image_mime, self::SUPPORTED_IMAGE_FORMATS)) {
                    // Upload de la nouvelle image et création d'un attachment
                    $new_attachment = new ProductAttachment($url);    
                    $new_attach_meta = ProductAttachment::iu_get_attachment_meta($new_attachment);
                    $file = ProductAttachment::iu_get_attachment_file($new_attachment);
                    $old_meta = ProductAttachment::iu_get_attachment_meta($attachment_id);
                    $old_original_file = ProductAttachment::iu_get_attachment_file($attachment_id);

                    // Mise à jour le fichier de l'attachment
                    ProductAttachment::iu_update_attachment_file_and_meta($attachment_id, $file, $old_original_file, $new_attach_meta, $old_meta, $new_attachment);

                    echo json_encode(ProductAttachment::iu_get_attachment_url($attachment_id));
                } else {
                    echo json_encode(__('L\'image a un poids supérieur au poids maximal autorisé', self::TEXT_DOMAIN));
                }
            }
            die();
        }


        /**
         * Récupérer toutes les images des produits (AJAX)
         *
         * @return void
         */
        function iu_get_all_products_images()
        {
            $permission = check_ajax_referer('iu_upscale_nonce', 'security', false);
            if ($permission == false) {
                add_action('admin_notices', array(&$this, 'iu_error_notice'), 10, 1);
                $this->iu_error_notice(__('Permission non accordée.', self::TEXT_DOMAIN));
            } else {
                $attachments = ProductAttachment::iu_get_all_attachments();
                $width = get_option(self::CLAID_WIDTH_META_KEY);
                $attachments = array_filter($attachments, function ($attachment) use ($width) {
                    return ProductAttachment::iu_get_attachment_width($attachment['ID']) != $width;
                });

                if (!get_option(self::CLAID_SMALLER_IMAGES_META_KEY)) {
                    $attachments = $this->iu_get_products_attachments_by_size_limit($attachments);
                }
                echo json_encode($attachments);
            }
            die();
        }

        /**
         * Création d'une column dans la liste média de l'admin
         *
         * @param [type] $cols
         * @return void
         */
        function iu_media_list_column($cols)
        {
            $cols["iu_upscale"] = self::PLUGIN_NAME;
            return $cols;
        }


        /**
         * Affichage du bouton d'upscale pour les fichiers concernés (Produit, taille d'image)
         *
         * @return void
         */
        function iu_media_list_column_action()
        {
            global $post;
            $id = $post->ID;
            $post_types = ['product', 'product_variation'];
            $url = ProductAttachment::iu_get_attachment_url($id);
            $post_parent = ProductAttachment::iu_get_attachment_post_parent($id);

            $width = ProductAttachment::iu_get_attachment_width($id);
            $height = ProductAttachment::iu_get_attachment_height($id);
        ?>
            <?php if ($post_parent !== null && in_array($post_parent->post_type, $post_types)) : ?>
                <?php if (
                    (
                        get_option(self::CLAID_SMALLER_IMAGES_META_KEY) == 0
                        && $width > get_option(self::CLAID_WIDTH_META_KEY, self::DEFAULT_RESIZE_WIDTH)
                        && $height > get_option(self::CLAID_WIDTH_META_KEY, self::DEFAULT_RESIZE_WIDTH))
                    || get_option(self::CLAID_SMALLER_IMAGES_META_KEY) == 1
                ) : ?>
                    <button type="button" data-type-submit='upscale-item' data-attachment-url='<?= $url ?>' data-attachment-id='<?= $id ?>' class="button">Redimensionner</button>
                <?php endif; ?>
<?php endif;
        }
        /**
         * Récupération des attachments en fonction de la taille définie
         *
         * @param [type] $attachments
         * @return array
         */
        protected function iu_get_products_attachments_by_size_limit($attachments): array
        {
            $width = get_option(self::CLAID_WIDTH_META_KEY);
            $attachments = array_filter($attachments, function ($attachment) use ($width) {
                return ProductAttachment::iu_get_attachment_width($attachment['ID']) > $width && ProductAttachment::iu_get_attachment_height($attachment['ID']) > $width;
            });

            return $attachments;
        }



        /**
         * Renvoie des données poids et type mime d'une image récupérer d'une url via cURL
         *
         * @param [type] $url
         * @return array
         */
        protected function iu_get_remote_file_data($url): array
        {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HEADER, 1);
            curl_setopt($ch, CURLOPT_NOBODY, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            curl_close($ch);

            $results = explode("\n", trim(curl_exec($ch)));
            $image_mime = null;
            foreach ($results as $line) {
                if (strtolower(strtok($line, ':')) == 'content-type') {
                    $parts = explode(":", $line);
                    $image_mime = trim($parts[1]);
                }
            }

            return array(
                'filesize' => $size,
                'type' => $image_mime,
            );
        }

    }
}

new IngeniusUpscalePlugin();
