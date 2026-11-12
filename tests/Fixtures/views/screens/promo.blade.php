<column class="p-4">
    <text key="title">Promo {{ $id }}</text>
    <text>Likes {{ $likes }}</text>
    <button @navigate.fade="/blade-promos/{{ $id + 1 }}">More</button>
    <button @navigate.back>Close</button>
    <button @press="like">Like</button>
</column>
