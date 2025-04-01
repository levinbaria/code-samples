/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { toggleFormat, getActiveFormat, useAnchor } from '@wordpress/rich-text';
import {
	RichTextToolbarButton,
	FontSizePicker,
	__experimentalLetterSpacingControl as LetterSpacingControl,
	LineHeightControl,
	useSettings,
} from '@wordpress/block-editor';
import { Popover, SelectControl, Button } from '@wordpress/components';
import { useState, useMemo } from '@wordpress/element';
import { inlineTypography } from '@wordpress/icons';

// Utility functions
const serializeStyle = ( styleObj ) => {
	return (
		Object.entries( styleObj )
			.filter( ( [ , value ] ) => value )
			.map(
				( [ key, value ] ) =>
					key.replace( /([A-Z])/g, '-$1' ).toLowerCase() + ':' + value
			)
			.join( ';' ) + ';'
	);
};

const parseStyle = ( styleString ) => {
	const styleObj = {};
	if ( styleString ) {
		styleString.split( ';' ).forEach( ( style ) => {
			const [ key, value ] = style.split( ':' );
			if ( key && value ) {
				const camelKey = key
					.trim()
					.replace( /-([a-z])/g, ( match, letter ) =>
						letter.toUpperCase()
					);
				styleObj[ camelKey ] = value.trim();
			}
		} );
	}
	return styleObj;
};

// Constants
const TEXT_TRANSFORM_OPTIONS = [
	{ label: __( 'None' ), value: '' },
	{ label: __( 'Uppercase' ), value: 'uppercase' },
	{ label: __( 'Lowercase' ), value: 'lowercase' },
	{ label: __( 'Capitalize' ), value: 'capitalize' },
];

const name = 'core/inline-typography';
const title = __( 'Inline Typography' );

const Edit = ( { isActive, value, onChange, contentRef } ) => {
	const [ showSettings, setShowSettings ] = useState( false );
	const activeFormat = getActiveFormat( value, name );
	const computedStyles = useMemo( () => {
		return activeFormat && activeFormat.attributes.style
			? parseStyle( activeFormat.attributes.style )
			: {};
	}, [ activeFormat ] );

	const {
		fontFamily = '',
		fontSize = '',
		letterSpacing = '',
		textTransform = '',
		lineHeight = '',
	} = computedStyles;
	const themeFontFamiliesSetting = useSettings( 'typography.fontFamilies' );
	// Check if it's an array and if its first element contains a "theme" property.
	const themeFontFamilies = useMemo( () => {
		if (
			Array.isArray( themeFontFamiliesSetting ) &&
			themeFontFamiliesSetting.length > 0 &&
			themeFontFamiliesSetting[ 0 ].theme
		) {
			return themeFontFamiliesSetting[ 0 ].theme;
		}
		return [];
	}, [ themeFontFamiliesSetting ] );
	// If themeFontFamilies exist, use them; otherwise, use a static fallback.
	const dynamicFontFamilyOptions = useMemo( () => {
		const options = [ { label: __( 'Default' ), value: '' } ];
		if ( themeFontFamilies.length > 0 ) {
			themeFontFamilies.forEach( ( { fontName, fontFamilyName } ) => {
				options.push( {
					label: fontName,
					value: fontFamilyName,
				} );
			} );
		} else {
			options.push(
				{ label: 'Arial', value: 'Arial, sans-serif' },
				{ label: 'Helvetica', value: 'Helvetica, sans-serif' },
				{ label: 'Georgia', value: 'Georgia, serif' },
				{
					label: 'Times New Roman',
					value: '"Times New Roman", Times, serif',
				}
			);
		}
		return options;
	}, [ themeFontFamilies ] );

	// Compute the popover's anchor using Gutenberg's useAnchor.
	const popoverAnchor = useAnchor( {
		editableContentElement: contentRef ? contentRef.current : null,
		settings: { ...inlineTypographyButton, isActive },
	} );

	const updateInlineFormat = ( newStyles ) => {
		const styleString = serializeStyle( newStyles );
		let newValue = toggleFormat( value, { type: name } );
		newValue = toggleFormat( newValue, {
			type: name,
			attributes: { style: styleString },
		} );
		onChange( newValue );
	};

	// onUpdate merges the new value into the computed styles.
	const onUpdate = ( key, newVal ) => {
		const updatedStyles = {
			...computedStyles,
			[ key ]: newVal,
		};
		updateInlineFormat( updatedStyles );
	};

	return (
		<>
			<RichTextToolbarButton
				icon={ inlineTypography }
				title={ title }
				onClick={ () => {
					if ( ! isActive ) {
						onChange(
							toggleFormat( value, {
								type: name,
								attributes: { style: '' },
							} )
						);
					}
					setShowSettings( true );
				} }
				isActive={ isActive }
				role="menuitemcheckbox"
			/>
			{ showSettings && popoverAnchor && (
				<Popover
					anchor={ popoverAnchor }
					position="bottom center"
					onClose={ () => setShowSettings( false ) }
				>
					<div style={ { padding: '20px' } }>
						<SelectControl
							label={ __( 'Font' ) }
							value={ fontFamily }
							options={ dynamicFontFamilyOptions }
							onChange={ ( newVal ) =>
								onUpdate( 'fontFamily', newVal )
							}
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
						<div style={ { marginTop: '20px' } }>
							<FontSizePicker
								value={ fontSize }
								fallbackFontSize={ 16 }
								onChange={ ( newSize ) =>
									onUpdate( 'fontSize', newSize )
								}
								__next40pxDefaultSize
							/>
						</div>
						<div style={ { marginTop: '20px' } }>
							<LetterSpacingControl
								label={ __( 'Letter Spacing' ) }
								value={ letterSpacing }
								onChange={ ( newVal ) =>
									onUpdate( 'letterSpacing', newVal )
								}
								__unstableInputWidth="auto"
								__next40pxDefaultSize
							/>
						</div>
						<div style={ { marginTop: '20px' } }>
							<SelectControl
								label={ __( 'Letter Case' ) }
								value={ textTransform }
								options={ TEXT_TRANSFORM_OPTIONS }
								onChange={ ( newVal ) =>
									onUpdate( 'textTransform', newVal )
								}
								__next40pxDefaultSize
								__nextHasNoMarginBottom
							/>
						</div>
						<div
							style={ { marginTop: '20px' } }
							className="inline-typography__line-height"
						>
							<LineHeightControl
								value={ lineHeight }
								onChange={ ( newVal ) =>
									onUpdate( 'lineHeight', newVal )
								}
								__next40pxDefaultSize
							/>
						</div>
						<div
							style={ {
								marginTop: '20px',
								display: 'flex',
								justifyContent: 'end',
							} }
						>
							<Button
								isTertiary
								onClick={ () => {
									onChange(
										toggleFormat( value, { type: name } )
									);
									setShowSettings( false );
								} }
								__next40pxDefaultSize
							>
								{ __( 'Remove' ) }
							</Button>
						</div>
					</div>
				</Popover>
			) }
		</>
	);
};

export const inlineTypographyButton = {
	name,
	title,
	tagName: 'span',
	className: 'wp_block_inline_typography',
	attributes: { style: 'style' },
	edit: Edit,
};
