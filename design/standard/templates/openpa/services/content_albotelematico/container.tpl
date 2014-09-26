{def $current_state =  "in_pubblicazione"
     $attribute_filter = array( array( 'state', "in", $openpa.content_albotelematico.default_state_ids ) )}

{if is_set( $view_parameters.stato )}
   {set $current_state = $view_parameters.stato}
{/if}

{if $current_state|eq( 'archivio' )}
  {set $attribute_filter = array( array( 'state', "=", $openpa.content_albotelematico.archive_state_ids ) )}
{/if}


<div class="state-navigation block">
  <a class="button{if $current_state|eq('in_pubblicazione')} defaultbutton{/if}" href="{$node.url_alias|ezurl(no)}">In pubblicazione</a>
  <a class="button{if $current_state|eq('archivio')} defaultbutton{/if}" href="{concat( $node.url_alias, '/(stato)/archivio')|ezurl(no)}">Archivio</a>
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
     $children_count = fetch( 'content', 'list_count', hash( 'parent_node_id', $node.node_id, 'attribute_filter',  ))}

{if $children_count}

  {def $children = fetch( 'content', 'list', hash( 'parent_node_id', $node.node_id,
                                                   'limit', $page_limit,
                                                   'attribute_filter', $attribute_filter,
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
           page_uri=$node.url_alias
           item_count=$children_count
           view_parameters=$view_parameters
           item_limit=$page_limit}

{else}
  <div class="message-warning">
    <p>Non sono attualmente presenti atti in questa sezione</p>
  </div>
{/if}