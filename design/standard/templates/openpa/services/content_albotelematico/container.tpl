{def $current_state =  $openpa.content_albotelematico.default_state}
{if is_set( $view_parameters.stato )}
  {def $current_state_identifier = $view_parameters.stato}
  {if is_set( $openpa.content_albotelematico.states[$current_state_identifier] )}
    {set $current_state = $openpa.content_albotelematico.states[$current_state_identifier]}
  {/if}
{/if}

<div class="state-navigation block">
{foreach  $openpa.content_albotelematico.states as $identifier => $state}
  <a class="button{if $current_state.state_object.id|eq($state.state_object.id)} defaultbutton{/if}" href="{concat( $node.url_alias, '/(stato)/', $identifier)|ezurl(no)}">{$state.name}</a>
{/foreach}
</div>


{* ATTRIBUTI : mostra i contenuti del nodo *}
{include name = attributi_principali uri = 'design:parts/openpa/attributi_principali.tpl' node = $node}

<div class="attributi-principali float-break col col-notitle">
  {if and( is_set($node.data_map.description), $node.data_map.description.has_content )}
    <div class="col-content-design">
      {attribute_view_gui attribute=$node.data_map.description}
    </div>
  {/if}
</div>


{def $page_limit = openpaini( 'GestioneFigli', 'limite_paginazione', 25 )
     $children_count = fetch( 'content', 'list_count', hash( 'parent_node_id', $node.node_id, 'attribute_filter', array( array( 'state', "=", $current_state.state_object.id ) ) ))}

{if $children_count}

  {def $children = fetch( 'content', 'list', hash( 'parent_node_id', $node.node_id,
                                                   'limit', $page_limit,
                                                   'attribute_filter', array( array( 'state', "=", $current_state.state_object.id ) ),
                                                   'sort_by', $node.sort_array,
                                                   'offset', $view_parameters.offset ) )}

  {if is_set( $style )|not}{def $style='col-odd'}{/if}

  <div class="content-view-children block">
    {foreach $children as $child }
      {if $style|eq('col-even')}{set $style='col-odd'}{else}{set $style='col-even'}{/if}
      <div class="{$style} col col-notitle float-break">
        <div class="col-content"><div class="col-content-design">
          {node_view_gui view='line' show_image='no' content_node=$child}
        </div></div>
      </div>
    {/foreach}
  </div>

  {include name=navigator
           uri='design:navigator/google.tpl'
           page_uri=concat( $node.url_alias, '/(stato)/', $current_state_identifier)
           item_count=$children_count
           view_parameters=$view_parameters
           item_limit=$page_limit}

{else}
  <div class="message-warning">
    <p>Non sono attualmente presenti atti in questa sezione</p>
  </div>
{/if}