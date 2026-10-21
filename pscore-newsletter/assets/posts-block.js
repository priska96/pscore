(function (blocks, element, components, blockEditor, apiFetch) {
    'use strict';
    var el = element.createElement;
    var Fragment = element.Fragment;
    var InspectorControls = blockEditor.InspectorControls;
    var useState = element.useState;
    var useEffect = element.useEffect;

    function numberControl(label, value, key, setAttributes, min, max) {
        return el(components.RangeControl, {
            label: label,
            value: value,
            min: min,
            max: max,
            onChange: function (next) { var update = {}; update[key] = next; setAttributes(update); }
        });
    }

    function colorControl(label, value, key, setAttributes) {
        return el(components.TextControl, {
            label: label,
            value: value,
            type: 'text',
            help: 'CSS hex color, for example #2f7da5',
            onChange: function (next) { var update = {}; update[key] = next; setAttributes(update); }
        });
    }

    blocks.registerBlockType('pscore-newsletter/posts', {
        title: 'Recent Posts',
        icon: 'welcome-widgets-menus',
        category: 'pscore-newsletter',
        description: 'Dynamically show the latest standard or custom posts when the email is generated.',
        attributes: {
            postType: { type: 'string', default: 'post' },
            postsToShow: { type: 'number', default: 2 },
            showImage: { type: 'boolean', default: true },
            showDate: { type: 'boolean', default: true },
            showExcerpt: { type: 'boolean', default: false },
            buttonLabel: { type: 'string', default: 'Read more...' },
            imageWidth: { type: 'number', default: 52 },
            contentPadding: { type: 'number', default: 20 },
            rowSpacing: { type: 'number', default: 28 },
            backgroundColor: { type: 'string', default: '#ffffff' },
            dateColor: { type: 'string', default: '#1d2327' },
            dateFontSize: { type: 'number', default: 18 },
            titleColor: { type: 'string', default: '#1d2327' },
            titleFontSize: { type: 'number', default: 34 },
            titleFontWeight: { type: 'number', default: 400 },
            excerptColor: { type: 'string', default: '#333333' },
            excerptFontSize: { type: 'number', default: 16 },
            buttonBackground: { type: 'string', default: '#2f7da5' },
            buttonColor: { type: 'string', default: '#ffffff' },
            buttonFontSize: { type: 'number', default: 20 }
        },
        edit: function (props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            var postState = useState([]);
            var posts = postState[0];
            var setPosts = postState[1];
            var loadingState = useState(true);
            var loading = loadingState[0];
            var setLoading = loadingState[1];

            useEffect(function () {
                setLoading(true);
                apiFetch({ path: '/pscore-newsletter/v1/posts?type=' + encodeURIComponent(attributes.postType) + '&per_page=' + attributes.postsToShow }).then(function (items) {
                    setPosts(items || []);
                    setLoading(false);
                }).catch(function () {
                    setPosts([]);
                    setLoading(false);
                });
            }, [attributes.postType, attributes.postsToShow]);

            return el(Fragment, {},
                el(InspectorControls, {},
                    el(components.PanelBody, { title: 'Recent Posts', initialOpen: true },
                        el(components.SelectControl, {
                            label: 'Content type',
                            value: attributes.postType,
                            options: (window.PSCORENewsletterBlock && window.PSCORENewsletterBlock.postTypes) || [{ value: 'post', label: 'Post (post)' }],
                            onChange: function (value) { setAttributes({ postType: value }); }
                        }),
                        numberControl('Number of posts', attributes.postsToShow, 'postsToShow', setAttributes, 1, 20),
                        el(components.ToggleControl, { label: 'Show featured image', checked: attributes.showImage, onChange: function (value) { setAttributes({ showImage: value }); } }),
                        el(components.ToggleControl, { label: 'Show date', checked: attributes.showDate, onChange: function (value) { setAttributes({ showDate: value }); } }),
                        el(components.ToggleControl, { label: 'Show excerpt', checked: attributes.showExcerpt, onChange: function (value) { setAttributes({ showExcerpt: value }); } }),
                        el(components.TextControl, { label: 'Button label', value: attributes.buttonLabel, onChange: function (value) { setAttributes({ buttonLabel: value }); } })
                    ),
                    el(components.PanelBody, { title: 'Layout', initialOpen: false },
                        numberControl('Image width (%)', attributes.imageWidth, 'imageWidth', setAttributes, 25, 70),
                        numberControl('Space between image and text (px)', attributes.contentPadding, 'contentPadding', setAttributes, 0, 60),
                        numberControl('Space below each post (px)', attributes.rowSpacing, 'rowSpacing', setAttributes, 0, 80),
                        colorControl('Row background', attributes.backgroundColor, 'backgroundColor', setAttributes)
                    ),
                    el(components.PanelBody, { title: 'Date style', initialOpen: false },
                        colorControl('Date color', attributes.dateColor, 'dateColor', setAttributes),
                        numberControl('Date font size (px)', attributes.dateFontSize, 'dateFontSize', setAttributes, 10, 40)
                    ),
                    el(components.PanelBody, { title: 'Title style', initialOpen: false },
                        colorControl('Title color', attributes.titleColor, 'titleColor', setAttributes),
                        numberControl('Title font size (px)', attributes.titleFontSize, 'titleFontSize', setAttributes, 16, 60),
                        el(components.SelectControl, {
                            label: 'Title weight', value: String(attributes.titleFontWeight),
                            options: [300, 400, 500, 600, 700, 800].map(function (weight) { return { value: String(weight), label: String(weight) }; }),
                            onChange: function (value) { setAttributes({ titleFontWeight: parseInt(value, 10) }); }
                        })
                    ),
                    el(components.PanelBody, { title: 'Excerpt style', initialOpen: false },
                        colorControl('Excerpt color', attributes.excerptColor, 'excerptColor', setAttributes),
                        numberControl('Excerpt font size (px)', attributes.excerptFontSize, 'excerptFontSize', setAttributes, 10, 30)
                    ),
                    el(components.PanelBody, { title: 'Button style', initialOpen: false },
                        colorControl('Button background', attributes.buttonBackground, 'buttonBackground', setAttributes),
                        colorControl('Button text color', attributes.buttonColor, 'buttonColor', setAttributes),
                        numberControl('Button font size (px)', attributes.buttonFontSize, 'buttonFontSize', setAttributes, 10, 32)
                    )
                ),
                el('div', { className: props.className, style: { border: '1px solid #dcdcde', padding: '20px' } },
                    el('strong', {}, 'Recent Posts'),
                    el('p', {}, 'The latest ' + attributes.postsToShow + ' post(s) are fetched when the preview or email is generated.'),
                    loading ? el(components.Spinner) : posts.map(function (post) {
                        return el('div', { key: post.id, style: { display: 'flex', gap: '14px', marginTop: '14px', alignItems: 'flex-start' } },
                            attributes.showImage && post.image ? el('img', { src: post.image, alt: '', style: { width: attributes.imageWidth + '%', height: 'auto' } }) : null,
                            el('div', {},
                                attributes.showDate ? el('em', {}, post.date) : null,
                                el('div', { style: { color: attributes.titleColor, fontSize: attributes.titleFontSize + 'px', lineHeight: 1.15 } }, post.title),
                                attributes.buttonLabel ? el('span', { style: { display: 'inline-block', marginTop: '10px', padding: '8px 14px', background: attributes.buttonBackground, color: attributes.buttonColor } }, attributes.buttonLabel) : null
                            )
                        );
                    })
                )
            );
        },
        save: function () { return null; }
    });

    blocks.registerBlockType('pscore-newsletter/social-icons', {
        title: 'Social Icons as Images',
        icon: 'share',
        category: 'pscore-newsletter',
        description: 'Email-safe social profile links using PNG images. Unlike the standard Gutenberg Social Icons block, this block does not use SVG icons or depend on Gutenberg frontend styles.',
        attributes: {
            instagramUrl: { type: 'string', default: 'https://www.instagram.com/pscorekorea/?hl=en' },
            facebookUrl: { type: 'string', default: 'https://www.facebook.com/PscoreKorea/' },
            xUrl: { type: 'string', default: 'https://twitter.com/PSCORE911' },
            youtubeUrl: { type: 'string', default: 'https://www.youtube.com/pscore911' },
            linkedinUrl: { type: 'string', default: 'https://www.linkedin.com/company/pscore/mycompany/' },
            iconSize: { type: 'number', default: 28 },
            iconGap: { type: 'number', default: 10 },
            alignment: { type: 'string', default: 'left' }
        },
        edit: function (props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            var icons = (window.PSCORENewsletterBlock && window.PSCORENewsletterBlock.socialIcons) || {};
            var networks = [
                { key: 'instagram', label: 'Instagram', attribute: 'instagramUrl' },
                { key: 'facebook', label: 'Facebook', attribute: 'facebookUrl' },
                { key: 'x', label: 'X', attribute: 'xUrl' },
                { key: 'youtube', label: 'YouTube', attribute: 'youtubeUrl' },
                { key: 'linkedin', label: 'LinkedIn', attribute: 'linkedinUrl' }
            ];

            return el(Fragment, {},
                el(InspectorControls, {},
                    el(components.PanelBody, { title: 'Social profiles', initialOpen: true },
                        networks.map(function (network) {
                            return el(components.TextControl, {
                                key: network.key,
                                label: network.label + ' URL',
                                value: attributes[network.attribute],
                                type: 'url',
                                help: 'Leave empty to hide this icon.',
                                onChange: function (value) { var update = {}; update[network.attribute] = value; setAttributes(update); }
                            });
                        })
                    ),
                    el(components.PanelBody, { title: 'Icon style', initialOpen: true },
                        numberControl('Icon size (px)', attributes.iconSize, 'iconSize', setAttributes, 16, 64),
                        numberControl('Space between icons (px)', attributes.iconGap, 'iconGap', setAttributes, 0, 40),
                        el(components.SelectControl, {
                            label: 'Alignment',
                            value: attributes.alignment,
                            options: [
                                { value: 'left', label: 'Left' },
                                { value: 'center', label: 'Center' },
                                { value: 'right', label: 'Right' }
                            ],
                            onChange: function (value) { setAttributes({ alignment: value }); }
                        })
                    )
                ),
                el('div', {
                    className: props.className,
                    style: { display: 'flex', justifyContent: attributes.alignment === 'center' ? 'center' : (attributes.alignment === 'right' ? 'flex-end' : 'flex-start'), gap: attributes.iconGap + 'px', padding: '12px 0' }
                }, networks.map(function (network) {
                    if (!attributes[network.attribute] || !icons[network.key]) return null;
                    return el('img', {
                        key: network.key,
                        src: icons[network.key],
                        alt: network.label,
                        width: attributes.iconSize,
                        height: attributes.iconSize,
                        style: { display: 'block', width: attributes.iconSize + 'px', height: attributes.iconSize + 'px' }
                    });
                }))
            );
        },
        save: function () { return null; }
    });
})(window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor, window.wp.apiFetch);
