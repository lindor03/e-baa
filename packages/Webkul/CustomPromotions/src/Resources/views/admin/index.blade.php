<x-admin::layouts>
    <x-slot:title>Custom Promotions</x-slot:title>

    <div class="mt-3 flex items-center justify-between gap-2">
        <p class="text-xl font-bold text-gray-800 dark:text-white">Custom Promotions</p>

        <a href="{{ route('admin.custompromotions.create') }}" class="primary-button">
            New Promotion
        </a>
    </div>

    <div class="mt-4 overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b text-left">
                    <th class="p-2">ID</th>
                    <th class="p-2">Name</th>
                    <th class="p-2">From</th>
                    <th class="p-2">To</th>
                    <th class="p-2">Active</th>
                    <th class="p-2">Actions</th>
                </tr>
            </thead>

            <tbody>
                @forelse ($items as $row)
                    <tr class="border-b">
                        <td class="p-2">{{ $row->id }}</td>
                        <td class="p-2">{{ $row->name }}</td>
                        <td class="p-2">{{ $row->from }}</td>
                        <td class="p-2">{{ $row->to }}</td>
                        <td class="p-2">{{ $row->is_active ? 'Yes' : 'No' }}</td>
                        <td class="p-2">
                            <a class="text-blue-600 hover:underline"
                               href="{{ route('admin.custompromotions.edit', $row->id) }}">Edit</a>

                            <form class="inline-block ml-2"
                                  method="POST"
                                  action="{{ route('admin.custompromotions.destroy', $row->id) }}"
                                  onsubmit="return confirm('Delete this promotion?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-red-600 hover:underline">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="p-4 text-center text-gray-500" colspan="6">No promotions yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $items->links() }}
    </div>
</x-admin::layouts>
