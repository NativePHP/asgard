<native:row :key="$id" class="p-2">
    <text>{{ $label }}</text>
    <button @press="like({{ $id }})">Like</button>
</native:row>
