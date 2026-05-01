<?php
/**
 * Bricks element: Latest Pledges.
 *
 * @package SalawatCounter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Bricks\Element' ) ) {
	return;
}

class Salawat_Latest_Pledges_Element extends \Bricks\Element {
	public $category = 'general';
	public $name     = 'salawat-latest-pledges';
	public $icon     = 'ti-list';
	public $scripts  = array();

	/**
	 * Element label.
	 *
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'Salawat Latest Pledges', 'salawat-counter' );
	}

	/**
	 * Set builder controls.
	 *
	 * @return void
	 */
	public function set_controls() {
		$this->controls['title'] = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Title', 'salawat-counter' ),
			'type'    => 'text',
			'default' => esc_html__( 'Latest Pledges', 'salawat-counter' ),
		);

		$this->controls['showTitle'] = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Show title', 'salawat-counter' ),
			'type'    => 'checkbox',
			'default' => true,
		);

		$this->controls['limit'] = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Number of pledges', 'salawat-counter' ),
			'type'    => 'number',
			'min'     => 1,
			'max'     => 50,
			'step'    => 1,
			'default' => 5,
		);

		$this->controls['order'] = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Order', 'salawat-counter' ),
			'type'    => 'select',
			'options' => array(
				'desc' => esc_html__( 'Newest first', 'salawat-counter' ),
				'asc'  => esc_html__( 'Oldest first', 'salawat-counter' ),
			),
			'default' => 'desc',
		);

		$this->controls['showDate'] = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Show date', 'salawat-counter' ),
			'type'    => 'checkbox',
			'default' => true,
		);

		$this->controls['dateFormat'] = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Date format', 'salawat-counter' ),
			'type'    => 'text',
			'default' => 'jS F Y',
		);

		$this->controls['showMessage'] = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Show message', 'salawat-counter' ),
			'type'    => 'checkbox',
			'default' => true,
		);

		$this->controls['showAmountLabel'] = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Show amount label', 'salawat-counter' ),
			'type'    => 'checkbox',
			'default' => true,
		);

		$this->controls['amountLabel'] = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Amount label', 'salawat-counter' ),
			'type'    => 'text',
			'default' => esc_html__( 'Amount Donated', 'salawat-counter' ),
		);

		$this->controls['anonymousLabel'] = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Anonymous label', 'salawat-counter' ),
			'type'    => 'text',
			'default' => esc_html__( 'Anonymous', 'salawat-counter' ),
		);

		$this->controls['emptyText'] = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Empty text', 'salawat-counter' ),
			'type'    => 'text',
			'default' => esc_html__( 'No pledges have been submitted yet.', 'salawat-counter' ),
		);

		$this->controls['columns'] = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Columns', 'salawat-counter' ),
			'type'    => 'number',
			'min'     => 1,
			'max'     => 4,
			'step'    => 1,
			'default' => 1,
		);

		$this->controls['gap'] = array(
			'tab'   => 'style',
			'label' => esc_html__( 'Card gap', 'salawat-counter' ),
			'type'  => 'number',
			'units' => true,
			'css'   => array(
				array(
					'property' => 'gap',
					'selector' => '.salawat-latest-list',
				),
			),
		);

		$this->controls['cardBackground'] = array(
			'tab'   => 'style',
			'label' => esc_html__( 'Card background', 'salawat-counter' ),
			'type'  => 'color',
			'css'   => array(
				array(
					'property' => 'background-color',
					'selector' => '.salawat-latest-card',
				),
			),
		);

		$this->controls['cardPadding'] = array(
			'tab'   => 'style',
			'label' => esc_html__( 'Card padding', 'salawat-counter' ),
			'type'  => 'dimensions',
			'css'   => array(
				array(
					'property' => 'padding',
					'selector' => '.salawat-latest-card',
				),
			),
		);

		$this->controls['cardBorder'] = array(
			'tab'   => 'style',
			'label' => esc_html__( 'Card border', 'salawat-counter' ),
			'type'  => 'border',
			'css'   => array(
				array(
					'property' => 'border',
					'selector' => '.salawat-latest-card',
				),
			),
		);

		$this->controls['cardShadow'] = array(
			'tab'   => 'style',
			'label' => esc_html__( 'Card shadow', 'salawat-counter' ),
			'type'  => 'box-shadow',
			'css'   => array(
				array(
					'property' => 'box-shadow',
					'selector' => '.salawat-latest-card',
				),
			),
		);

		$this->controls['titleTypography'] = array(
			'tab'   => 'style',
			'label' => esc_html__( 'Title typography', 'salawat-counter' ),
			'type'  => 'typography',
			'css'   => array(
				array(
					'property' => 'typography',
					'selector' => '.salawat-latest-title',
				),
			),
		);

		$this->controls['nameTypography'] = array(
			'tab'   => 'style',
			'label' => esc_html__( 'Name typography', 'salawat-counter' ),
			'type'  => 'typography',
			'css'   => array(
				array(
					'property' => 'typography',
					'selector' => '.salawat-latest-name',
				),
			),
		);

		$this->controls['messageTypography'] = array(
			'tab'   => 'style',
			'label' => esc_html__( 'Message typography', 'salawat-counter' ),
			'type'  => 'typography',
			'css'   => array(
				array(
					'property' => 'typography',
					'selector' => '.salawat-latest-message',
				),
			),
		);

		$this->controls['amountTypography'] = array(
			'tab'   => 'style',
			'label' => esc_html__( 'Amount typography', 'salawat-counter' ),
			'type'  => 'typography',
			'css'   => array(
				array(
					'property' => 'typography',
					'selector' => '.salawat-latest-amount',
				),
			),
		);

		$this->controls['amountColor'] = array(
			'tab'   => 'style',
			'label' => esc_html__( 'Amount color', 'salawat-counter' ),
			'type'  => 'color',
			'css'   => array(
				array(
					'property' => 'color',
					'selector' => '.salawat-latest-amount',
				),
			),
		);

		$this->controls['accentColor'] = array(
			'tab'   => 'style',
			'label' => esc_html__( 'Message accent color', 'salawat-counter' ),
			'type'  => 'color',
			'css'   => array(
				array(
					'property' => 'border-left-color',
					'selector' => '.salawat-latest-message',
				),
			),
		);
	}

	/**
	 * Render element.
	 *
	 * @return void
	 */
	public function render() {
		$settings = is_array( $this->settings ) ? $this->settings : array();

		$this->set_attribute( '_root', 'class', 'salawat-latest-pledges-element' );

		echo '<div ' . $this->render_attributes( '_root' ) . '>';
		echo Salawat_Counter_Plugin::instance()->render_latest_pledges(
			array(
				'limit'             => isset( $settings['limit'] ) ? $settings['limit'] : 5,
				'title'             => isset( $settings['title'] ) ? $settings['title'] : esc_html__( 'Latest Pledges', 'salawat-counter' ),
				'show_title'        => array_key_exists( 'showTitle', $settings ) ? ! empty( $settings['showTitle'] ) : true,
				'show_date'         => array_key_exists( 'showDate', $settings ) ? ! empty( $settings['showDate'] ) : true,
				'show_message'      => array_key_exists( 'showMessage', $settings ) ? ! empty( $settings['showMessage'] ) : true,
				'show_amount_label' => array_key_exists( 'showAmountLabel', $settings ) ? ! empty( $settings['showAmountLabel'] ) : true,
				'order'             => isset( $settings['order'] ) ? $settings['order'] : 'desc',
				'date_format'       => isset( $settings['dateFormat'] ) ? $settings['dateFormat'] : 'jS F Y',
				'amount_label'      => isset( $settings['amountLabel'] ) ? $settings['amountLabel'] : esc_html__( 'Amount Donated', 'salawat-counter' ),
				'anonymous_label'   => isset( $settings['anonymousLabel'] ) ? $settings['anonymousLabel'] : esc_html__( 'Anonymous', 'salawat-counter' ),
				'empty_text'        => isset( $settings['emptyText'] ) ? $settings['emptyText'] : esc_html__( 'No pledges have been submitted yet.', 'salawat-counter' ),
				'columns'           => isset( $settings['columns'] ) ? $settings['columns'] : 1,
			)
		);
		echo '</div>';
	}
}
