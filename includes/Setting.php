<?php

namespace iu_plugin;

if (!class_exists('Setting')) {
    class Setting
    {
        private $menu_slug;
        private $fields;
        private $section_id;
        private $sections;
        private $after_submit_callback;
        protected const TEXT_DOMAIN = 'iu-plugin';

        public function __construct($menu_slug, $after_submit_callback = null)
        {
            $this->menu_slug = $menu_slug;
            $this->after_submit_callback = $after_submit_callback;
            $this->fields    = [];
            $this->sections  = [];
            $this->after_submit_callback !== null && add_action('after_submit_form', $this->after_submit_callback);
        }
        /**
         * Affichage du formulaire en fonction des options renseignées
         *
         * @param array $option
         * @return void
         */
        public function show_form($option = [])
        {
            settings_errors();
            if (!current_user_can('manage_options')) {
                return;
            }
            if (isset($_GET['settings-updated'])) {
                do_action('after_submit_form');
            }

?>
            <form method="post" action="options.php">
                <?php
                foreach ($this->sections as $section) :
                    settings_fields($section[0]);
                    do_settings_sections($this->menu_slug);
                endforeach;
                $text = isset($option['buttonText']) ? $option['buttonText'] : '';
                $attributes = isset($option['attributes']) ? $option['attributes'] : '';
                submit_button($text, 'primary', 'submit', true, $attributes);
                ?>
            </form>
        <?php
        }

        /**
         * Rendre le hook add settings disponible à l'initialisation
         *
         * @return void
         */
        public function save()
        {
            add_action('admin_init', [$this, 'add_settings']);
        }

        /**
         * Ajoute les sections et les champs
         *
         * @return void
         */
        public function add_settings()
        {
            foreach ($this->sections as $section) {
                add_settings_section(
                    $section[0],
                    $section[1],
                    [$this, 'default_section_callback'],
                    $this->menu_slug
                );
            }
            foreach ($this->fields as $field) {
                add_settings_field(
                    $field['id'],
                    $field['label'],
                    [$this, 'default_field_callback'],
                    $this->menu_slug,
                    $field['section_id'],
                    $field
                );
                register_setting($field['section_id'], $field['id'], $field['sanitizer']);
            }
        }

        /**
         * Affichage des champs
         *
         * @param [type] $data
         * @return void
         */
        public function default_field_callback($data)
        {
        ?>
            <?php if (isset($data['type']) && $data['type'] == 'select') : ?>
                <select id="<?= esc_attr($data['id']); ?>" name="<?= esc_attr($data['id']); ?>">
                    <?php foreach ($data['options'] as $option_value => $option_label) : ?>
                        <option value="<?php echo esc_attr($option_value); ?>" <?php echo  get_option($data['id']) == $option_value ? 'selected' : ''; ?>><?php echo esc_html($option_label); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else : ?>
                <input type="<?= esc_attr($data['type']); ?>" id="<?= esc_attr($data['id']); ?>" name="<?= esc_attr($data['id']); ?>" value="<?= $data['type'] == 'text' ? esc_attr(get_option($data['id'])) : "1"; ?>" <?= esc_attr($data['autocomplete']); ?> <?= esc_attr($data['input_class']); ?> <?= esc_attr($data['class']); ?> placeholder="<?= esc_attr($data['placeholder']); ?>" <?= esc_attr($data['type']) == 'checkbox' && get_option($data['id']) == 1 ? 'checked' : '' ?>>
            <?php endif; ?>
            <p class="description"><?= $data['description'] ?></p>
<?php
        }

        /**
         * Création d'un champ en complétant les informations manquantes
         * Ajout du champ à une section
         *
         * @param [type] $data
         * @return void
         */
        public function add_field($data)
        {
            $default_data = [
                'type'           => '',
                'id'             => '',
                'description'    => '',
                'sanitizer'   => '',
                'label'          => '',
                'tip'            => '',
                'min'            => '',
                'max'            => '',
                'input_class'    => '',
                'class'          => '',
                'options'        => array(__('Sélectionnez une option', self::TEXT_DOMAIN) => ''),
                'default_option' => '',
                'autocomplete'   => 'on',
                'placeholder'    => ''
            ];
            $data = array_merge($default_data, $data);
            $data['section_id'] = $this->section_id;
            array_push($this->fields, $data);
        }
        /**
         * Création d'une section
         *
         * @param [type] $id
         * @param string $title
         * @return void
         */
        public function add_section($id, $title = '')
        {
            array_push($this->sections, [$id, $title]);
            $this->section_id = $id;
        }
        /**
         * Affichage du section
         *
         * @return void
         */
        public function default_section_callback()
        {
        }
    }
}
