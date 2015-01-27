<h1>Collocazioni Albo Telematico</h1>

{foreach $objects as $object}
    <h2><a href="{$object.main_node.url_alias|ezurl(no)}">{$object.name|wash()}</a></h2>
    <ul>
    {foreach $object.data_map as $attribute}
      {if $attribute.data_type_string|eq( 'ezobjectrelationlist' )}
        <li><strong>{$attribute.contentclass_attribute_name}:</strong><br/> {attribute_view_gui attribute=$attribute}</li>
      {/if}
    {/foreach}
    </ul>
{/foreach}
