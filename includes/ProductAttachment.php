<?php

namespace iu_plugin;

use WP_Post;

if (!class_exists('ProductAttachment')) {
    class ProductAttachment
    {
        private $id;
        private $source_url;

        /**
         * Télécharge l'image de l'url spécifiée et enregistre un attachment lié à celle-ci
         *
         * @param [type] $source_url
         */
        public function __construct($source_url)
        {
            $this->source_url = $source_url;
            $this->id = media_sideload_image($this->source_url, 0, null, 'id');
        }

        public function iu_get_attachment_id()
        {
            return $this->id;
        }

        /**
         * Récupération de la largeur de l'image de l'attachment spécifié
         *
         * @param [type] $attachment_id
         * @param string $size
         * @return integer
         */
        public static function iu_get_attachment_width($attachment_id, $size = 'full'): int | null
        {
            $attachment_attributes =  wp_get_attachment_image_src($attachment_id, $size);
            return $attachment_attributes[1];
        }

        /**
         * Récupération de la hauteur de l'image de l'attachment spécifié
         *
         * @param [type] $attachment_id
         * @param string $size
         * @return integer
         */
        public static function iu_get_attachment_height($attachment_id, $size = 'full'): int | null
        {
            $attachment_attributes =  wp_get_attachment_image_src($attachment_id, $size);
            return $attachment_attributes[2];
        }

        /**
         * Récupération de l'URL de l'attachment spécifié
         *
         * @param [type] $attachment_id
         * @return string
         */
        public static function iu_get_attachment_url($attachment_id): string
        {
            return wp_get_attachment_url($attachment_id);
        }

        /**
         * Récupération du post parent de l'attachment spécifié
         *
         * @param [type] $attachment_id
         * @return WP_Post|null
         */
        public static function iu_get_attachment_post_parent($attachment_id): WP_Post|null
        {
            return get_post_parent($attachment_id);
        }

        /**
         * Récupération de l'ID d'un attachment à partir d'une URL
         *
         * @param [type] $image_url
         * @return void
         */
        public static function iu_get_attachment_id_from_url($attachment_url): string
        {
            global $wpdb;
            $attachment = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE guid='%s';", $attachment_url));
            return $attachment[0];
        }

        /**
         * Récupération des données de l'attachment spécifié
         *
         * @param [type] $attachment_id
         * @return WP_Post|array|null
         */
        public static function iu_find_attachment_by_id($attachment_id): WP_Post|array|null
        {
            return get_post($attachment_id);
        }

        /**
         * Récupération des métadonnées de l'attachment spécifié
         *
         * @param [type] $attachment_id
         * @return array|false
         */
        public static function iu_get_attachment_meta($attachment_id): array|false
        {
            return wp_get_attachment_metadata($attachment_id);
        }

        /**
         * Mise à jour du fichier de l'attachment spécifié
         * Mise à jour des métadonnées de l'attachment spécifié
         * Suppression de l'ancien fichier et de ces variations de size dans le dossier d'upload et la base de données
         *
         * @param [type] $attachment_id
         * @param [type] $file
         * @param [type] $original_file
         * @param [type] $attachment_meta
         * @param [type] $original_attachment_meta
         * @param [type] $new_attachment_id
         * @return void
         */
        public static function iu_update_attachment_file_and_meta($attachment_id, $file, $original_file, $attachment_meta, $original_attachment_meta, $new_attachment_id): void
        {
            update_post_meta($attachment_id, '_wp_attached_file', $file);
            update_post_meta($attachment_id, '_wp_attachment_metadata', $attachment_meta);
            $upload_dir = wp_upload_dir();
            @unlink($original_file);
            foreach ($original_attachment_meta['sizes'] as $size) {
                $file_to_delete = $size['file'];
                $path = wp_mkdir_p($upload_dir['path']) ? $upload_dir['path'] : $upload_dir['basedir'];
                $file_path = $path . '/' . $file_to_delete;
                @unlink($file_path);
            }

            global $wpdb;
            $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->posts WHERE ID='%s';", $new_attachment_id));
        }

        /**
         * Récupération du fichier de l'attachment spécifié
         *
         * @param [type] $attachment_id
         * @return string|false
         */
        public static function iu_get_attachment_file($attachment_id): string|false
        {
            return get_attached_file($attachment_id);
        }

        /**
         * Récupération de tous les attachments produits et variation de produits
         *
         * @return array
         */
        public static function iu_get_all_attachments(): array
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
    }

    /**
     * Get the value of id
     */
}
