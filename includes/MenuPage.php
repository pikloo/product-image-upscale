<?php

namespace iu_plugin;

if (!class_exists('MenuPage')) {
    class MenuPage
    {

        private $page_title;
        private $menu_title;
        private $capability;
        private $menu_slug;
        private $callback;
        private $icon_url;
        private $sub_menu_pages;
        protected const TEXT_DOMAIN = 'iu-plugin';


        public function __construct($page_title, $menu_title, $capability = 'manage_options',  $menu_slug, $callback, $icon_url = 'dashicons-admin-generic', $sub_menu_pages = [])
        {
            $this->page_title = $page_title;
            $this->menu_title = $menu_title;
            $this->capability = $capability;
            $this->menu_slug = $menu_slug;
            $this->callback = $callback;
            $this->icon_url = $icon_url;
            $this->sub_menu_pages = $sub_menu_pages;
        }
        /**
         * Ajout d'une page menu
         *
         * @return void
         */
        public function iu_add_settings_page()
        {
            add_menu_page(
                __($this->page_title, self::TEXT_DOMAIN),
                __($this->menu_title, self::TEXT_DOMAIN),
                $this->capability,
                $this->menu_slug,
                $this->callback,
                $this->icon_url
            );

            foreach ($this->sub_menu_pages as $sub_menu_page) {
                $menu_slug = isset($sub_menu_page['menu_slug']) ? $sub_menu_page['menu_slug'] : $this->menu_slug;
                $callback = isset($sub_menu_page['callback']) ? $sub_menu_page['callback'] : $this->callback;
                add_submenu_page($this->menu_slug, '', __($sub_menu_page['label'], self::TEXT_DOMAIN), 'read', $menu_slug, $callback);
            }
        }

        /**
         * Rendre disponible le hook d'ajout de la page menu 
         *
         * @return void
         */
        public function iu_menu_page_run()
        {
            add_action('admin_menu', array(&$this, 'iu_add_settings_page'));
        }

        /**
         * Getter de récupération du slug de la page
         *
         * @return void
         */
        public function iu_get_menu_slug()
        {
            return $this->menu_slug;
        }

        /**
         * Ajout d'une sous-page au menu
         *
         * @param array $sub_menu_page
         * @return void
         */
        public function iu_add_sub_menu_page($sub_menu_page)
        {
            $this->sub_menu_pages[] = $sub_menu_page;
        }
    }
}
