<column class="p-4 gap-2" key="stats">
    <text class="font-bold">Hello, {{ $this->name }}</text>
    <text>Count: {{ $count }}</text>
    @foreach ($items as $i => $item)
        <native:row :key="$i">
            <text>{{ $item }}</text>
        </native:row>
    @endforeach
    <button @press="bump(2)">Bump</button>
    <button @press.client="celebrate({{ $name }})">Party</button>
    <divider />
</column>
