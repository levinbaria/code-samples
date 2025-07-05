/**
 * Retrieves the translation of text.
 *
 * @see https://developer.wordpress.org/block-editor/packages/packages-i18n/
 */
import { __ } from "@wordpress/i18n";

/**
 * React hook that is used to mark the block wrapper element.
 *
 * @see https://developer.wordpress.org/block-editor/packages/packages-block-editor/
 */
import {
    RichText,
    useBlockProps,
    InspectorControls,
    PanelColorSettings,
    MediaUpload,
    MediaUploadCheck,
} from "@wordpress/block-editor";

/**
 * React hook that is used to mark the components element.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/components/
 */
import {
    PanelBody,
    TextControl,
    RangeControl,
    SelectControl,
    RadioControl,
    ToggleControl,
    Button,
    Tooltip,
    Toolbar,
    ButtonGroup,
    Panel,
    Spinner,
    Popover,
    FocalPointPicker,
    BoxControl,
} from "@wordpress/components";

/**
 * React hook that is used to mark the packages element.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-element/
 */
import {
    Fragment,
    useEffect,
    useState,
    useRef,
    useCallback,
} from "@wordpress/element";
import { decodeEntities } from '@wordpress/html-entities';
import { useSelect } from "@wordpress/data";
import { useMemo, React } from "react";
import { Icon, edit, seen } from "@wordpress/icons";
import { headingOptions } from '../../js/constants';
import "./editor.scss";

/**
 * The edit function describes the structure of your block in the context of the
 * editor. This represents what the editor will render when the block is used.
 *
 * @param {Object}   root0                    - The root object.
 * @param {Object}   root0.attributes         - The attributes of the root object.
 * @param {string}   root0.attributes.heading - The heading attribute of the block.
 * @param {Function} root0.setAttributes      - Function to set the attributes.
 * @param {string}   root0.className          - The class name.
 * @see https://developer.wordpress.org/block-editor/developers/block-api/block-edit-save/#edit
 * @return {WPElement} Element to render.
 */
export default function (props) {
    const { attributes, setAttributes, clientId } = props;

    const {
        blockID,
        blockBgColor,
        sectionPadding,
        isPreview,
        heading,
        headingTag,
        headingColor,
        headingBgColor,
        selectedPostType,
        type,
        noOfPost,
        postTitleColor,
        postBgColor,
        removeContainer,
    } = attributes;

    // Initialize block ID.
    useEffect(() => {
        if (!blockID) {
            setAttributes({ blockID: `sp-recent-posts-${clientId}` });
        }
    }, []);

    // Get post types using useSelect
    const { postTypes } = useSelect((select) => {
        const { getPostTypes } = select("core");
        return {
            postTypes: getPostTypes({ per_page: -1 }) || [],
        };
    }, []);

    const postTypeOptions = useMemo(() => {
        return (
            postTypes
                ?.filter(
                    (postType) =>
                        postType.viewable &&
                        !["page", "attachment", "media", "product"].includes(postType.slug)
                )
                .map((postType) => ({
                    label: postType.labels?.singular_name || postType.name,
                    value: postType.rest_base || postType.slug,
                })) || []
        );
    }, [postTypes]);

    const prevPostTypeRef = useRef();

    // Only clear on _real_ postType changes.
    useEffect(() => {
        if (
            prevPostTypeRef.current &&
            prevPostTypeRef.current !== selectedPostType
        ) {
            setAttributes({ selectedPosts: [] });
        }
        prevPostTypeRef.current = selectedPostType;
    }, [selectedPostType]);

    // Pull in posts for preview.
    const posts = useSelect(
        (select) => {
            if (!selectedPostType) {
                return null;
            }
            const { getEntityRecords } = select('core');
            const postTypeObj = postTypes.find(
                (pt) =>
                    pt.slug === selectedPostType ||
                    pt.rest_base === selectedPostType
            ) || {};
            const entityName = postTypeObj.slug || selectedPostType;
            return getEntityRecords('postType', entityName, {
                per_page: noOfPost || 5,
                orderby: 'date',
                order: type === 'asc' ? 'asc' : 'desc',
                _embed: true,
            });
        },
        [selectedPostType, noOfPost, type, postTypes]
    );

    const blockStyle = {};
    blockBgColor && (blockStyle.backgroundColor = blockBgColor);
    sectionPadding &&
        (blockStyle.padding = `${sectionPadding.top} ${sectionPadding.right} ${sectionPadding.bottom} ${sectionPadding.left}`);

    const blockProps = useBlockProps({
        className: `sp-recent-posts ${blockID}`,
    });

    const HeadingTag = headingTag;

    return (
        <Fragment>
            <InspectorControls>
                <div className="code-sample-block-sidebar">
                    <PanelBody
                        title={__("Posts Related Settings", "code-sample")}
                        initialOpen={true}
                    >
                        <SelectControl
                            label={__("Post Type", "code-sample")}
                            value={selectedPostType}
                            options={postTypeOptions}
                            onChange={(newType) => {
                                setAttributes({
                                    selectedPostType: newType,
                                    selectedPosts: [],
                                });
                            }}
                        />
                        <RangeControl
                            label={__("Number of Posts", "code-sample")}
                            value={noOfPost ? noOfPost : 3}
                            onChange={(noOfPost) => setAttributes({ noOfPost })}
                            min={1}
                            max={20}
                        />
                        <SelectControl
                            label={__("Content Ordering", "code-sample")}
                            value={type}
                            options={[
                                { label: __("Newest First", "code-sample"), value: "recent" },
                                { label: __("Oldest First", "code-sample"), value: "asc" },
                            ]}
                            onChange={(selectedType) => {
                                setAttributes({ type: selectedType });
                            }}
                            help={__(
                                "Select “Newest First” to show your latest stories first; “Oldest First” to show earlier stories first.",
                                "code-sample"
                            )}
                        />
                    </PanelBody>
                    <PanelBody title={__("General Settings", "code-sample")} initialOpen={false}>
                        <SelectControl
                            label={__('Heading Tag', 'code-sample')}
                            value={headingTag}
                            options={headingOptions}
                            onChange={(headingTag) => {
                                setAttributes({ headingTag });
                            }}
                            help={__("Use H1 only once on a page.", "code-sample")}
                        />
                        <ToggleControl
                            label={__('Remove Container', 'code-sample')}
                            checked={removeContainer}
                            onChange={(value) => setAttributes({ removeContainer: value })}
                        />
                        <div style={{ marginBottom: "20px" }}>
                            <BoxControl
                                label={__("Section Padding", "code-sample")}
                                values={sectionPadding}
                                onChange={(sectionPadding) => setAttributes({ sectionPadding })}
                                allowReset={false}
                            />
                        </div>
                    </PanelBody>
                </div>
            </InspectorControls>
            <InspectorControls group="styles">
                <div className="code-sample-block-sidebar">
                    <PanelColorSettings
                        title={__("Color Settings", "code-sample")}
                        colorSettings={[
                            {
                                value: blockBgColor,
                                onChange: (newColor) => setAttributes({ blockBgColor: newColor }),
                                label: __("Background Color", "code-sample"),
                                enableAlpha: true,
                            },
                            {
                                value: headingBgColor,
                                onChange: (color) => setAttributes({ headingBgColor: color }),
                                label: __('Heading Backgroung Color', 'code-sample'),
                                enableAlpha: true,
                            },
                            {
                                value: postTitleColor,
                                onChange: (color) => setAttributes({ postTitleColor: color }),
                                label: __('Post Title', 'code-sample'),
                                enableAlpha: true,
                            },
                            {
                                value: postBgColor,
                                onChange: (color) => setAttributes({ postBgColor: color }),
                                label: __('Post Background Color', 'code-sample'),
                                enableAlpha: true,
                            },
                        ]}
                        initialOpen={false}
                    />
                </div>
            </InspectorControls>
            <div className="preview-container" style={{ marginTop: "20px" }}>
                <Button
                    variant="secondary"
                    onClick={() => {
                        setAttributes({ isPreview: !isPreview });
                    }}
                >
                    {isPreview ? (
                        <span>
                            <Icon icon={edit} />
                        </span>
                    ) : (
                        <span>
                            <Icon icon={seen} />
                        </span>
                    )}
                    {isPreview ? __("Edit", "code-sample") : __("Preview", "code-sample")}
                </Button>
            </div>
            <div id={blockID} {...blockProps}>
                <div className="sp-recent-posts__wrapper" style={blockStyle}>
                    <div className={`${removeContainer ? 'sp-recent-posts-container' : 'container'}`}>
                        <div className="sp-recent-posts__main">
                            <div className="sp-recent-posts__header">
                                {!isPreview ? (
                                    <RichText
                                        tagName={headingTag}
                                        value={heading}
                                        className="sp-recent-posts__heading"
                                        onChange={(value) => setAttributes({ heading: value })}
                                        placeholder={__('Add Heading', 'code-sample')}
                                        style={{
                                            color: headingColor,
                                            backgroundColor: headingBgColor
                                        }}
                                    />
                                ) : (
                                    <>
                                        {heading ? (
                                            <RichText.Content
                                                tagName={headingTag}
                                                value={heading}
                                                className="sp-recent-posts__heading"
                                                style={{
                                                    color: headingColor,
                                                    backgroundColor: headingBgColor
                                                }}
                                            />
                                        ) : (
                                            <HeadingTag
                                                className='sp-recent-posts__heading'
                                                style={{
                                                    color: headingColor,
                                                    backgroundColor: headingBgColor
                                                }}
                                            >
                                                {__('Add heading...', 'code-sample')}
                                            </HeadingTag>
                                        )}
                                    </>
                                )}
                            </div>
                            <ul className="sp-recent-posts__posts-wrapper">
                                {!posts ? (
                                    <Spinner />
                                ) : posts.length ? (
                                    posts.map((post, i) => {

                                        return (
                                            <li
                                                key={post.id}
                                                className="sp-recent-posts__individual-post"
                                                style={{ backgroundColor: postBgColor }}
                                            >
                                                <RichText.Content
                                                    tagName="p"
                                                    className="sp-recent-posts__post-title"
                                                    style={{ color: postTitleColor }}
                                                    value={`${decodeEntities( post.title.rendered )}...`}

                                                />
                                            </li>
                                        )
                                    })
                                ) : (
                                    <p>{__('No posts found.', 'code-sample')}</p>
                                )}
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </Fragment>
    );
}
