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

        function iu_uninstall()
        {
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
                'id' => 'iu_claid_bearer',
                'type' => 'text',
                'placeholder' => '1/mZ1edKKACtPAb7zGlwSzvs72PvhAbGmB8K1ZrGxpcNM',
                'sanitizer' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
            ]);
            $settings->add_field([
                'label' => __('Largeur (en pixels)', self::TEXT_DOMAIN),
                'id' => 'iu_claid_width',
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
                'id' => 'iu_claid_file_type',
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
                'id' => 'iu_claid_smaller_images',
                'description' => __('<span class="iu-desc-warning"><i class="fa-solid fa-triangle-exclamation"></i> Non recommandé : Les images pourraient être de faible qualité.</span> <br >Actuellement: ' . get_option('iu_claid_width', self::DEFAULT_RESIZE_WIDTH) . ' px', self::TEXT_DOMAIN),
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
            if (null !== get_option('iu_claid_bearer') && !empty(get_option('iu_claid_bearer'))) {
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
                        'Authorization: Bearer ' . get_option('iu_claid_bearer')
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

            $attachments = $this->iu_get_products_attachments();
            $total_attachments_count = count($attachments);

            if (!get_option('iu_claid_smaller_images')) {
                $attachments = $this->iu_get_products_attachments_by_size_limit($attachments);
            }

            $count_images_to_rescale = 0;
            foreach ($attachments as $attachment) {
                $image_attributes = wp_get_attachment_image_src($attachment['ID'], 'full');
                $image_width = $image_attributes[1];
                if ($image_width != get_option('iu_claid_width', self::DEFAULT_RESIZE_WIDTH)) {
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
                'bearer' => get_option('iu_claid_bearer', ''),
                'width' => get_option('iu_claid_width', self::DEFAULT_RESIZE_WIDTH),
                'output_filetype' => get_option('iu_claid_file_type', self::DEFAULT_RESIZE_FILETYPE),
                'error_message' => __('Une erreur est survenue lors du redimensionnement de l\'image. Veuillez vérifier votre clé API Claid AI et votre configuration.', self::TEXT_DOMAIN),
                'loading_message' => __('En cours...', self::TEXT_DOMAIN),
            ));
        }


        /**
         * Remplacement d'une image d'un attachment
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

                $attachment_id = $this->pippin_get_image_id($image_source);
                
                //Récupération de l'URL de la nouvelle image
                $url = $_REQUEST['newImage'];
                // $url = 'https://encrage.photo/wp-content/uploads/2024/05/Tales_from_ukraine1-.jpg'; //URL TEST

                $image_data = $this->iu_get_remote_file_data($url);

                $image_mime = $image_data['type'];

                if ($image_data['filesize'] < self::IMAGE_MAX_SIZE && in_array($image_mime, self::SUPPORTED_IMAGE_FORMATS)) {
                    // Upload de la nouvelle image et création d'un attachment
                    $new_attach_id = media_sideload_image($url, 0, null, 'id');
                    $new_attach_meta = wp_get_attachment_metadata($new_attach_id);
                    $file = $new_attach_meta['file'];
                    $old_meta = wp_get_attachment_metadata($attachment_id);
                    $old_original_file = get_attached_file($attachment_id);

                    // Mettre à jour le fichier de l'attachment
                    update_post_meta($attachment_id, '_wp_attached_file', $file);
                    // Mettre à jour les metadata de l'attachement
                    update_post_meta($attachment_id, '_wp_attachment_metadata', $new_attach_meta);
                    //Supprimer les anciens fichiers
                    $upload_dir = wp_upload_dir();
                    @unlink($old_original_file);
                    foreach ($old_meta['sizes'] as $size) {
                        $file_to_delete = $size['file'];
                        $path = wp_mkdir_p($upload_dir['path']) ? $upload_dir['path'] : $upload_dir['basedir'];
                        $file_path = $path . '/' . $file_to_delete;
                        @unlink($file_path);
                    }
                    //Supprimer l'attachment créé
                    $this->iu_delete_attachment_in_database($new_attach_id);

                    echo json_encode(wp_get_attachment_url($attachment_id));
                } else {
                    echo json_encode(__('L\'image a un poids supérieur au poids maximal autorisé', self::TEXT_DOMAIN));
                }
            }
            die();
        }


        /**
         * Récupérer toutes les images des produits
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
                $attachments = $this->iu_get_products_attachments();

                $width = get_option('iu_claid_width');
                $attachments = array_filter($attachments, function ($attachment) use ($width) {
                    $image_attributes = wp_get_attachment_image_src($attachment['ID'], 'full');
                    return $image_attributes[1] != $width;
                });

                if (!get_option('iu_claid_smaller_images')) {
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
            $url = wp_get_attachment_url($id);
            $post_parent = get_post_parent($id);
            $image_attributes = wp_get_attachment_image_src($id, 'full');
            $width = $image_attributes[1];
            $height = $image_attributes[2];
        ?>
            <?php if ($post_parent !== null && in_array($post_parent->post_type, $post_types)) : ?>
                <?php if (
                    (
                        get_option('iu_claid_smaller_images') == 0
                        && $width > get_option('iu_claid_width', self::DEFAULT_RESIZE_WIDTH)
                        && $height > get_option('iu_claid_width', self::DEFAULT_RESIZE_WIDTH))
                    || get_option('iu_claid_smaller_images') == 1
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
            $width = get_option('iu_claid_width');
            $attachments = array_filter($attachments, function ($attachment) use ($width) {
                $image_attributes = wp_get_attachment_image_src($attachment['ID'], 'full');
                return $image_attributes[1] > $width && $image_attributes[2] > $width;
            });

            return $attachments;
        }

        /**
         * Extraction de la base de données des attachments liés aux produits
         *
         * @return array
         */
        protected function iu_get_products_attachments(): array
        {
            global $wpdb;
            $attachment_posts = $wpdb->get_results(
                "
            SELECT
            ID,
            guid,
            post_parent as parent,
            post_status,
            (SELECT post_status FROM {$wpdb->prefix}posts wp2 WHERE wp2.ID = wp.post_parent AND (post_type = 'product' OR post_type = 'product_variation')) as parent_status,
            (SELECT post_date FROM {$wpdb->prefix}posts wp3 WHERE wp3.ID = wp.post_parent AND (post_type = 'product' OR post_type = 'product_variation')) as parent_date,
            (SELECT post_title FROM {$wpdb->prefix}posts wp3 WHERE wp3.ID = wp.post_parent AND (post_type = 'product' OR post_type = 'product_variation')) as parent_title
            FROM {$wpdb->prefix}posts wp
            INNER JOIN {$wpdb->prefix}postmeta AS metadata ON (wp.ID=metadata.post_id AND metadata.meta_key = '_wp_attachment_metadata')
            WHERE post_type = 'attachment'
            AND post_status = 'inherit'
            AND post_parent <> 0
            HAVING parent_status = 'publish'
            ORDER BY parent_date DESC
            ;",
                ARRAY_A
            );

            return $attachment_posts;
        }

        /**
         * Suppression d'un attachment de la base de données
         *
         * @param [type] $attachment_id
         * @return void
         */
        protected function iu_delete_attachment_in_database($attachment_id): void
        {
            global $wpdb;
            $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->posts WHERE ID='%s';", $attachment_id));
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
                    var_dump($parts);
                }
            }

            return array(
                'filesize' => $size,
                'type' => $image_mime,
            );
        }

        /**
         * Récupération de l'ID d'une image à partir d'une URL
         *
         * @param [type] $image_url
         * @return void
         */
        protected function pippin_get_image_id($image_url): string
        {
            global $wpdb;
            $attachment = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE guid='%s';", $image_url));
            return $attachment[0];
        }
    }
}

new IngeniusUpscalePlugin();
