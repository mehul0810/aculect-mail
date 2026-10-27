import { useMemo, useState } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews/wp';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import { __ } from '@wordpress/i18n';

export default function DataViewListing( {
	config,
	label = __( 'Provider performance', 'onesmtp' ),
} ) {
	const fields = useMemo(
		() =>
			config.fields.map( ( field ) => ( {
				...field,
				enableSorting: field.enableSorting !== false,
			} ) ),
		[ config.fields ]
	);
	const [ view, setView ] = useState( {
		type: 'table',
		perPage: 20,
		page: 1,
		search: '',
		filters: [],
		fields: fields.map( ( field ) => field.id ),
		layout: {},
	} );

	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( config.data, view, fields ),
		[ config.data, view, fields ]
	);
	return (
		<div className="onesmtp-dataviews-shell" aria-label={ label }>
			<DataViews
				data={ data }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				defaultLayouts={ { table: {} } }
				paginationInfo={ paginationInfo }
			/>
		</div>
	);
}
