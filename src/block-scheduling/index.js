/**
 * MSC Scheduled Visibility — Block-Level Scheduling
 *
 * Adds a sidebar panel in the Gutenberg editor that allows
 * per-block visibility scheduling.
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEntityProp } from '@wordpress/core-data';
import { PanelBody, ToggleControl, TextControl, Icon } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { calendar } from '@wordpress/icons';

/**
 * Main sidebar component.
 */
function BlockSchedulingSidebar() {
	const { selectedBlockClientId, blocks } = useSelect( ( select ) => {
		const blockEditor = select( 'core/block-editor' );
		return {
			selectedBlockClientId: blockEditor.getSelectedBlockClientId(),
			blocks: blockEditor.getBlocks(),
		};
	}, [] );

	const postType = useSelect( ( select ) => {
		return select( 'core/editor' ).getCurrentPostType();
	}, [] );

	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );

	const blockSchedules = meta?._mscsv_block_schedules
		? ( typeof meta._mscsv_block_schedules === 'string'
			? JSON.parse( meta._mscsv_block_schedules )
			: meta._mscsv_block_schedules )
		: {};

	/**
	 * Get the top-level index of the currently selected block.
	 */
	function getSelectedBlockIndex() {
		if ( ! selectedBlockClientId || ! blocks.length ) {
			return -1;
		}

		// Check top-level blocks.
		for ( let i = 0; i < blocks.length; i++ ) {
			if ( blocks[ i ].clientId === selectedBlockClientId ) {
				return i;
			}
			// Check if selected block is a child — attribute to parent (top-level).
			if ( isDescendant( blocks[ i ], selectedBlockClientId ) ) {
				return i;
			}
		}
		return -1;
	}

	/**
	 * Recursively check if a clientId is a descendant of a block.
	 */
	function isDescendant( block, clientId ) {
		if ( ! block.innerBlocks || ! block.innerBlocks.length ) {
			return false;
		}
		for ( const inner of block.innerBlocks ) {
			if ( inner.clientId === clientId ) {
				return true;
			}
			if ( isDescendant( inner, clientId ) ) {
				return true;
			}
		}
		return false;
	}

	const selectedIndex = getSelectedBlockIndex();
	const blockKey = selectedIndex >= 0 ? `block_${ selectedIndex }` : null;

	const currentSchedule = blockKey && blockSchedules[ blockKey ]
		? blockSchedules[ blockKey ]
		: { enabled: false, start_date: '', start_time: '', end_date: '', end_time: '' };

	/**
	 * Update a schedule field for the current block.
	 */
	function updateSchedule( field, value ) {
		if ( ! blockKey ) {
			return;
		}

		const updated = {
			...blockSchedules,
			[ blockKey ]: {
				...currentSchedule,
				[ field ]: value,
			},
		};

		setMeta( { ...meta, _mscsv_block_schedules: JSON.stringify( updated ) } );
	}

	/**
	 * Get a display label for the selected block.
	 */
	function getBlockLabel() {
		if ( selectedIndex < 0 || ! blocks[ selectedIndex ] ) {
			return '';
		}
		const block = blocks[ selectedIndex ];
		const name = block.name.replace( 'core/', '' ).replace( /[-/]/g, ' ' );
		return `#${ selectedIndex + 1 } — ${ name }`;
	}

	return (
		<>
			<PluginSidebarMoreMenuItem target="mscsv-block-scheduling">
				{ __( 'Block Scheduling', 'msc-scheduled-visibility' ) }
			</PluginSidebarMoreMenuItem>

			<PluginSidebar
				name="mscsv-block-scheduling"
				title={ __( 'Block Scheduling', 'msc-scheduled-visibility' ) }
				icon={ calendar }
			>
				<PanelBody>
					{ selectedIndex < 0 ? (
						<p>{ __( 'Select a block to configure its visibility schedule.', 'msc-scheduled-visibility' ) }</p>
					) : (
						<>
							<p style={ { marginBottom: '12px', fontWeight: 600 } }>
								{ getBlockLabel() }
							</p>

							<ToggleControl
								label={ __( 'Enable schedule for this block', 'msc-scheduled-visibility' ) }
								checked={ !! currentSchedule.enabled }
								onChange={ ( val ) => updateSchedule( 'enabled', val ? 1 : 0 ) }
							/>

							{ !! currentSchedule.enabled && (
								<>
									<TextControl
										label={ __( 'Visible from (date)', 'msc-scheduled-visibility' ) }
										type="date"
										value={ currentSchedule.start_date || '' }
										onChange={ ( val ) => updateSchedule( 'start_date', val ) }
									/>
									<TextControl
										label={ __( 'Visible from (time)', 'msc-scheduled-visibility' ) }
										type="time"
										value={ currentSchedule.start_time || '' }
										onChange={ ( val ) => updateSchedule( 'start_time', val ) }
									/>
									<TextControl
										label={ __( 'Visible until (date)', 'msc-scheduled-visibility' ) }
										type="date"
										value={ currentSchedule.end_date || '' }
										onChange={ ( val ) => updateSchedule( 'end_date', val ) }
									/>
									<TextControl
										label={ __( 'Visible until (time)', 'msc-scheduled-visibility' ) }
										type="time"
										value={ currentSchedule.end_time || '' }
										onChange={ ( val ) => updateSchedule( 'end_time', val ) }
									/>
								</>
							) }
						</>
					) }
				</PanelBody>

				{ blocks.length > 0 && (
					<PanelBody
						title={ __( 'All Block Schedules', 'msc-scheduled-visibility' ) }
						initialOpen={ false }
					>
						{ blocks.map( ( block, index ) => {
							const key = `block_${ index }`;
							const schedule = blockSchedules[ key ];
							const hasSchedule = schedule && schedule.enabled;
							const name = block.name.replace( 'core/', '' ).replace( /[-/]/g, ' ' );

							return (
								<div key={ key } style={ {
									padding: '4px 0',
									borderBottom: '1px solid #ddd',
									opacity: hasSchedule ? 1 : 0.5,
								} }>
									<span style={ { fontSize: '12px' } }>
										#{ index + 1 } { name }
										{ hasSchedule && (
											<Icon icon={ calendar } size={ 14 } style={ { marginLeft: 4, verticalAlign: 'middle' } } />
										) }
									</span>
								</div>
							);
						} ) }
					</PanelBody>
				) }
			</PluginSidebar>
		</>
	);
}

registerPlugin( 'mscsv-block-scheduling', {
	render: BlockSchedulingSidebar,
} );
