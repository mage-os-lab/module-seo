/**
 * Logo Source select component.
 *
 * Extends the standard Magento select component to show the logo_upload
 * fileUploader field when "Upload Custom SEO Logo" (value 0) is selected,
 * and hide it when "Use Design Logo" (value 1) is selected.
 *
 * Using a custom component is the only reliable cross-version way to do
 * conditional visibility in Magento UI components — switcherConfig has
 * inconsistent XSD support and imports/exports cannot invert a boolean.
 */
define([
    'Magento_Ui/js/form/element/select',
    'uiRegistry'
], function (Select, registry) {
    'use strict';

    return Select.extend({

        /**
         * After the component initialises, apply the initial visibility state
         * based on the value already loaded from the data provider.
         *
         * @returns {this}
         */
        initialize: function () {
            this._super();
            this.toggleUploader(this.value());
            return this;
        },

        /**
         * Override onUpdate so we react to every change.
         *
         * @param {string} value
         */
        onUpdate: function (value) {
            this._super(value);
            this.toggleUploader(value);
        },

        /**
         * Show the logo_upload field when value is '0' (custom upload),
         * hide it when value is '1' (use design logo).
         *
         * Uses a short timeout so the target component is guaranteed to be
         * rendered in the registry before we try to access it.
         *
         * @param {string} value
         */
        toggleUploader: function (value) {
            var targetName = this.parentName + '.logo_upload';

            registry.async(targetName)(function (uploader) {
                if (value === '0' || value === 0) {
                    uploader.show();
                } else {
                    uploader.hide();
                }
            });
        }
    });
});